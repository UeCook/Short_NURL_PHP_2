#!/bin/bash
# Short_NURL v2.0.3 部署验证脚本
# 用法: bash tests/verify-deploy.sh [BASE_URL]
# 示例: bash tests/verify-deploy.sh https://s.example.com

BASE="${1:-http://127.0.0.1}"
DIR="$(cd "$(dirname "$0")/.." && pwd)"
PASS=0; FAIL=0; WARN=0
VERIFY_REQUEST_DELAY="${VERIFY_REQUEST_DELAY:-3}"

ok()   { PASS=$((PASS+1)); printf "  \033[0;32mPASS\033[0m  %s\n" "$1"; }
fail() { FAIL=$((FAIL+1)); printf "  \033[0;31mFAIL\033[0m  %s\n" "$1"; [ -n "$2" ] && printf "        %s\n" "$2"; }
warn() { WARN=$((WARN+1)); printf "  \033[0;33mWARN\033[0m  %s\n" "$1"; [ -n "$2" ] && printf "        %s\n" "$2"; }
chk()  { local r; r=$(eval "$2" 2>&1); if echo "$r" | grep -qF "$3"; then ok "$1"; else fail "$1" "want[$3] got[$(echo "$r"|head -1|cut -c1-120)]"; fi; }
chk_http() { local r; r=$(eval "$2" 2>&1); if echo "$r" | grep -qF "$3"; then ok "$1"; else fail "$1" "HTTP $3 expected, got: $r"; fi; }

echo "============================================"
echo " Short_NURL v2.0.3 Deployment Verification"
echo " Target: $BASE"
echo " Root:   $DIR"
echo "============================================"

# ── 0. 文件完整性 ──
echo ""
echo "[0] File Integrity"
for f in api/api.php headless/headless.php index.html cli/nurl cli/nurl-key core/config.php core/src/autoload.php; do
  if [ -f "$DIR/$f" ]; then ok "$f"; else fail "$f" "missing"; fi
done

for f in frontend/app.js frontend/style.css frontend/theme-init.js frontend/basecoat.min.css frontend/basecoat.min.js; do
  if [ -f "$DIR/$f" ]; then ok "$f"; else fail "$f" "missing"; fi
done

for f in backend/lua/shorturl/init.lua backend/lua/shorturl/redirect.lua backend/lua/shorturl/internal.lua backend/lua/shorturl/expire_sweep.lua backend/lua/shorturl/config.lua; do
  if [ -f "$DIR/$f" ]; then ok "$f"; else fail "$f" "missing"; fi
done

# 按内容扫描全部文件（不按后缀过滤），含 cli/nurl 与 cli/nurl-key 等无后缀脚本
BOM_SCANNED=0; BOM_FOUND=0
while IFS= read -r f; do
  BOM_SCANNED=$((BOM_SCANNED + 1))
  if [ "$(head -c 3 "$f" 2>/dev/null | od -An -tx1 | tr -d ' \n')" = "efbbbf" ]; then
    BOM_FOUND=1
    fail "BOM-free: ${f#$DIR/}" "UTF-8 BOM found; it can send output before PHP headers"
  fi
done < <(find "$DIR" -type f \
  -not -path '*/.git/*' \
  -not -path '*/backend/data/*' \
  -not -path '*/nginx/logs/*')
if [ "$BOM_FOUND" -eq 0 ]; then ok "BOM scan: $BOM_SCANNED files clean (suffix-free)"; fi

# ── 1. PHP 环境 ──
echo ""
echo "[1] PHP Environment"
PHP_CMD=""
if command -v php >/dev/null 2>&1; then
  PHP_CMD="php"
elif command -v docker >/dev/null 2>&1; then
  PHP_CONTAINER="${PHP_CONTAINER:-}"
  if [ -z "$PHP_CONTAINER" ]; then
    PHP_CONTAINER=$(docker ps --format '{{.Names}}' 2>/dev/null | awk 'tolower($0) ~ /php/ { print; exit }')
  fi
  if [ -n "$PHP_CONTAINER" ] && docker ps --format '{{.Names}}' | grep -qxF "$PHP_CONTAINER"; then
    PHP_CMD="docker exec $PHP_CONTAINER php"
  fi
fi
if [ -n "$PHP_CMD" ]; then
  PHPV=$($PHP_CMD -r 'echo PHP_VERSION;' 2>/dev/null)
  ok "PHP $PHPV ($PHP_CMD)"
  if $PHP_CMD -r 'exit(version_compare(PHP_VERSION, "8.1", ">=") ? 0 : 1);' 2>/dev/null; then
    ok "PHP >= 8.1"
  else
    fail "PHP >= 8.1" "found $PHPV"
  fi
  if $PHP_CMD -m 2>/dev/null | grep -qi curl; then ok "ext-curl"; else fail "ext-curl" "missing (required for hot-store sync)"; fi
  # Docker PHP sees paths under /www/sites/http/index/ (or /var/www/html/)
  if command -v php >/dev/null 2>&1; then
    SYN_DIR="$DIR"
  else
    # Try common Docker mount points
    for d in /www/sites/http/index /var/www/html /var/www/short-nurl; do
      if $PHP_CMD -r "exit(is_file('$d/api/api.php') ? 0 : 1);" 2>/dev/null; then SYN_DIR="$d"; break; fi
    done
  fi
  if [ -n "$SYN_DIR" ]; then
    if $PHP_CMD -l "$SYN_DIR/api/api.php" >/dev/null 2>&1; then ok "api/api.php syntax"; else fail "api/api.php syntax"; fi
    if $PHP_CMD -l "$SYN_DIR/headless/headless.php" >/dev/null 2>&1; then ok "headless/headless.php syntax"; else fail "headless/headless.php syntax"; fi
  else
    warn "PHP syntax check" "cannot resolve path inside PHP container"
  fi
else
  warn "PHP CLI" "not found in PATH (skip syntax check)"
fi

# ── 2. 数据目录权限 ──
echo ""
echo "[2] Data Directory"
DDIR="$DIR/backend/data"
if [ -d "$DDIR" ]; then
  ok "backend/data/ exists"
  if [ -r "$DDIR" ] && [ -w "$DDIR" ]; then ok "backend/data/ writable"; else fail "backend/data/ writable"; fi
  if [ -f "$DDIR/perm.json" ]; then ok "perm.json exists"; else warn "perm.json" "will be created on first use"; fi
  if [ -f "$DDIR/temp.json" ]; then ok "temp.json exists"; else warn "temp.json" "will be created on first use"; fi
  if [ -f "$DDIR/keys.json" ]; then ok "keys.json exists"; else warn "keys.json" "run: php cli/nurl -new"; fi
  if [ -f "$DDIR/internal_token" ]; then ok "internal_token exists"; else warn "internal_token" "run: php cli/nurl -itk"; fi
  OPS="$DDIR/.operations"
  if [ -d "$OPS" ]; then
    if [ -x "$OPS" ]; then ok ".operations/ has exec bit"; else fail ".operations/ has exec bit" "chmod 770 $OPS"; fi
  else
    warn ".operations/" "will be created on first write"
  fi
else
  fail "backend/data/" "directory missing"
fi

# ── 3. 配置检查 ──
echo ""
echo "[3] Configuration"
if grep -q "'domain' => '{" "$DIR/core/config.php" 2>/dev/null; then
  warn "domain placeholder" "edit core/config.php and set your domain"
else
  ok "domain configured"
fi
if grep -q "'domain'.*localhost\|'domain'.*127\.0\.0\.1" "$DIR/core/config.php" 2>/dev/null; then
  warn "domain is localhost" "edit core/config.php: set your public domain"
fi
if grep -q "'internal_host' => 'http://127.0.0.1:18500'" "$DIR/core/config.php" 2>/dev/null; then
  ok "internal_host: 127.0.0.1:18500"
elif grep -q "'internal_host'" "$DIR/core/config.php" 2>/dev/null; then
  ok "internal_host configured"
else
  warn "internal_host" "check core/config.php internal_host setting"
fi
if grep -q "'cold_fallback' => true" "$DIR/core/config.php" 2>/dev/null; then
  warn "cold_fallback=true" "masks hot-store failures (use false for strict mode)"
else
  ok "cold_fallback=false (strict)"
fi

# ── 4. HTTP 连通性 ──
echo ""
echo "[4] HTTP Connectivity"
sleep 0.3
chk_http "/ (WebUI)"          "curl -s -o /dev/null -w '%{http_code}' $BASE/" "200"
chk_http "/api/ping"          "curl -s -o /dev/null -w '%{http_code}' $BASE/api/ping" "200"
chk "/api/ping body"          "curl -s $BASE/api/ping" '"ok":true'

# ── 5. 静态资源 ──
echo ""
echo "[5] Static Assets"
for a in app.js style.css theme-init.js basecoat.min.css basecoat.min.js; do
  chk_http "frontend/$a" "curl -s -o /dev/null -w '%{http_code}' $BASE/frontend/$a" "200"
done

# ── 6. API 路由 ──
echo ""
echo "[6] API Routing"
api_delay() { sleep "$VERIFY_REQUEST_DELAY"; }
api_delay; chk_http "POST /api/create -> 401 (no key)" "curl -s -o /dev/null -w '%{http_code}' -X POST $BASE/api/create -H 'Content-Type: application/json' -d '{\"url\":\"https://x.com\"}'" "401"
api_delay; chk_http "GET /api/list -> 401 (no key)"    "curl -s -o /dev/null -w '%{http_code}' $BASE/api/list" "401"
api_delay; chk_http "GET /api/stat -> 401 (no key)"    "curl -s -o /dev/null -w '%{http_code}' $BASE/api/stat" "401"
api_delay; chk_http "POST /api/ping -> 405"            "curl -s -o /dev/null -w '%{http_code}' -X POST $BASE/api/ping" "405"
api_delay; chk_http "OPTIONS /api/create -> 204"       "curl -s -o /dev/null -w '%{http_code}' -X OPTIONS $BASE/api/create" "204"
api_delay; chk_http "GET /headless/api/list -> 401"    "curl -s -o /dev/null -w '%{http_code}' $BASE/headless/api/list" "401"

# ── 7. CORS ──
echo ""
echo "[7] CORS"
api_delay
CORS=$(curl -s -X OPTIONS $BASE/api/create -D - -o /dev/null)
if echo "$CORS" | grep -qi "Access-Control-Allow-Origin"; then ok "Allow-Origin header"; else fail "Allow-Origin header"; fi
if echo "$CORS" | grep -qi "Access-Control-Allow-Headers.*X-Token"; then ok "Allow-Headers (X-Token)"; else fail "Allow-Headers (X-Token)"; fi

# ── 8. 安全拦截 ──
echo ""
echo "[8] Security (blocked paths)"
for p in /api.php /headless.php /api/api.php /headless/headless.php /core/config.php /core/src/Auth.php /bootstrap/webui.php /backend/data/keys.json /backend/data/internal_token /backend/lua/shorturl/init.lua /tests/run.php /cli/nurl /cli/nurl-key /nurl /nurl-key /nurl.php /nurl-key.php /.env /.git/config; do
  chk_http "block $p" "curl -s -o /dev/null -w '%{http_code}' $BASE$p" "404"
done

# ── 9. 安全响应头 ──
echo ""
echo "[9] Security Headers"
HDRS=$(curl -sI $BASE/)
if echo "$HDRS" | grep -qi "X-Content-Type-Options.*nosniff"; then ok "nosniff"; else warn "nosniff"; fi
if echo "$HDRS" | grep -qi "X-Frame-Options.*DENY"; then ok "X-Frame-Options: DENY"; else warn "X-Frame-Options"; fi
if echo "$HDRS" | grep -qi "Content-Security-Policy"; then ok "CSP"; else warn "CSP" "recommended: add_header Content-Security-Policy"; fi

# ── 10. 内部 API（本机测试） ──
echo ""
echo "[10] Internal API (loopback)"
if command -v curl >/dev/null 2>&1; then
  R=$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:18500/internal/stat 2>/dev/null)
  if [ "$R" = "403" ]; then
    ok "Internal API reachable (403 without token)"
    TOK=$(cat "$DDIR/internal_token" 2>/dev/null)
    if [ -n "$TOK" ]; then
      R2=$(curl -s http://127.0.0.1:18500/internal/stat -H "LPA-Key: $TOK" 2>/dev/null)
      if echo "$R2" | grep -q "perm_count"; then ok "Internal API with token"; else fail "Internal API with token" "$R2"; fi
    else
      warn "Internal token test" "internal_token not found"
    fi
  elif [ "$R" = "000" ]; then
    warn "Internal API" "18500 not reachable from this host (expected if PHP is in Docker)"
  else
    fail "Internal API" "got HTTP $R"
  fi
fi

# ── 11. 短链跳转（需要密钥） ──
echo ""
echo "[11] Redirect Test (requires key)"
echo "  Skipping (manual test):"
echo "    1. Generate key:  php cli/nurl -new"
echo "    2. Create link:   php cli/nurl-key -key 'su_xxx' -add -url 'https://example.com' -ttl 0"
echo "    3. Test redirect: curl -I https://your-domain.com/xxxx"

# ── 汇总 ──
TOTAL=$((PASS+FAIL+WARN))
echo ""
echo "============================================"
if [ $FAIL -eq 0 ]; then
  printf " \033[0;32mDEPLOYMENT OK\033[0m   %d passed, %d warnings\n" "$PASS" "$WARN"
else
  printf " \033[0;31mISSUES FOUND\033[0m    %d passed, %d failed, %d warnings\n" "$PASS" "$FAIL" "$WARN"
fi
echo "============================================"
if [ $WARN -gt 0 ]; then
  echo ""
  echo " Warnings are non-blocking but recommended to fix."
fi
if [ $FAIL -gt 0 ]; then
  echo ""
  echo " Common fixes:"
  echo "   - 404 on /api/*    -> check Nginx location ^~ /api/ block"
  echo "   - 401 everywhere   -> generate keys: php cli/nurl -new"
  echo "   - 403 internal     -> check internal_token file permissions (0600)"
  echo "   - data_inaccessible-> chown backend/data/ to PHP user (e.g. www-data)"
  echo "   - .operations FAIL -> chmod 770 backend/data/.operations"
fi
echo ""
exit $FAIL
