# 宝塔面板部署 - Nginx 配置

本项目兼容带 `ngx_http_lua_module` 的 Nginx，也兼容 OpenResty。Docker/OpenResty 是推荐配置；
宝塔部署使用宿主机 PHP-FPM 的 Unix Socket 或 TCP 监听，不依赖 Docker 服务名。

## 适用场景
- 使用宝塔面板管理服务器
- OpenResty/Nginx 和 PHP-FPM 安装在宿主机
- 通过宝塔面板管理网站和配置

## 前置条件
1. 已安装宝塔面板
2. 已通过宝塔安装 OpenResty 或 Nginx
3. 已通过宝塔安装 PHP-FPM（8.1+）
4. 已创建网站并配置好域名

---

## 一、Nginx 主配置

在宝塔面板中，进入 **软件商店 → OpenResty/Nginx → 设置 → 配置修改**，在 `http {}` 块内添加：

```nginx
# ═══════════════════════════════════════════════════
# ShortURL 共享字典和限流区域
# ═══════════════════════════════════════════════════

    # ———— 共享字典 ——————————————————————————————————————
    lua_shared_dict su_url       10m;
    lua_shared_dict su_exp       2m;
    lua_shared_dict su_meta      1m;

    # ———— API 限流 ————————————————
    limit_req_zone $binary_remote_addr zone=api:10m rate=30r/m;
    limit_req_zone $binary_remote_addr zone=headless:10m rate=60r/m;

    # ———— 跳转限流：每个 IP 每秒 2 次，突发 10 次 ——————————
    limit_req_zone $binary_remote_addr zone=redirect_burst:10m rate=2r/s;
    # ———— Worker 初始化：注册定时器 ————————————————
    # 注意：不要使用 init_worker_by_lua_block，它与 WAF/第三方模块的
    # init_worker_by_lua_file 冲突（Nginx 全局仅允许一个 init_worker_by_lua_*）。
    # 定时器注册由 access_by_lua_block → init.init() → init.init_worker() 惰性完成。

    # ═══════════════════════════════════════════════════
    # 内部 Lua API（仅本地访问）
    # ═══════════════════════════════════════════════════
    server {
        listen 127.0.0.1:18500;
        server_name _;

        # 必须与 core/config.php 的 internal_token_path 指向同一文件
        set $su_internal_token_path "/www/wwwroot/s.example.com/backend/data/internal_token";

        location /internal/ {
            # 访问控制：仅允许本地回环（PHP 与 OpenResty 同机）
            allow 127.0.0.1;
            allow ::1;
            deny all;
            limit_except GET POST { deny all; }

            content_by_lua_block {
                package.path = "/www/wwwroot/s.example.com/backend/lua/?.lua;;;" .. package.path
                local internal = require "shorturl.internal"
                internal.dispatch()
            }
        }
    }
# ═══════════════════════[END]═════════════════════════
```

---

## 二、站点 Nginx 配置

在宝塔面板中，进入 **网站管理 → 对应网站 → 设置 → 配置文件**，**替换**整个 `server {}` 块：

```nginx
server
{
    listen 80;
    #listen 443 ssl http2;
    server_name s.example.com;
    index index.php index.html index.htm default.php default.htm default.html;
    root /www/wwwroot/s.example.com;

    #SSL-START SSL相关配置，请勿删除或修改本行带#的注释
    #ssl_certificate    /www/server/panel/vhost/cert/s.example.com/fullchain.pem;
    #ssl_certificate_key    /www/server/panel/vhost/cert/s.example.com/privkey.pem;
    #ssl_protocols TLSv1.2 TLSv1.3;
    #ssl_ciphers EECDH+CHACHA20:EECDH+CHACHA20-draft:EECDH+AES128:RSA+AES128:EECDH+AES256:RSA+AES256:EECDH+3DES:RSA+3DES:!MD5;
    #ssl_prefer_server_ciphers on;
    #ssl_session_timeout 10m;
    #ssl_session_cache shared:SSL:10m;
    #ssl_session_tickets on;
    #add_header Strict-Transport-Security "max-age=31536000";
    #error_page 497  https://$host$request_uri;

    #SSL-END

    #ERROR-PAGE-START  错误页配置，可以注释、删除或修改
    #error_page 404 /404.html;
    #error_page 502 /502.html;
    #ERROR-PAGE-END

    #PHP-INFO-START  PHP引用配置，可以注释或修改
    #清理禁用函数
    #include enable-php.conf;
    #清理PHPINFO
    #location ~ .*\.(php|php5)?$
    #{
    #    fastcgi_pass unix:/tmp/php-cgi-81.sock;
    #    fastcgi_index index.php;
    #    include fastcgi.conf;
    #}
    #PHP-INFO-END

    #REWRITE-START  URL重写规则引用,修改后将导致面板设置的伪静态规则失效
    #include /www/server/panel/vhost/rewrite/s.example.com.conf;
    #REWRITE-END

    # ═══════════════════════════════════════════════════
    # ShortURL 站点配置
    # ═══════════════════════════════════════════════════

    # ★ 业务参数
    set $su_php_fpm          "unix:/tmp/php-cgi-81.sock";  # PHP-FPM 地址（按部署环境修改）
    # 宝塔本机部署：unix:/tmp/php-cgi-XX.sock 或 127.0.0.1:9000
    # Docker 部署：172.18.0.1:9000（网桥）或 PHP 容器名:9000
    set $su_tz_offset        "+08:00";
    set $su_perm_path        "/www/wwwroot/s.example.com/backend/data/perm.json";
    set $su_temp_path        "/www/wwwroot/s.example.com/backend/data/temp.json";
    set $su_expire_interval  3600;
    set $su_redirect_code    302;

    # ★ 安全响应头
    add_header X-Content-Type-Options  "nosniff"          always;
    add_header X-Frame-Options         "DENY"             always;
    add_header Referrer-Policy         "no-referrer"      always;
    add_header Content-Security-Policy "default-src 'self'; frame-ancestors 'none'" always;
    add_header Permissions-Policy      "geolocation=(), camera=(), microphone=()" always;

    # ★ 拒绝未显式路由的 PHP 文件（防止 api.php / headless.php 等源码外泄）
    location ~ \.php$ {
        deny all;
        return 404;
    }
    location ~ /\. {
        deny all;
        return 404;
    }
    location ~* \.(md|yml|yaml|conf|log|sh|bak|sql|ini|json)$ {
        deny all;
        return 404;
    }

    # ★ 屏蔽敏感目录
    location ^~ /data/ {
        deny all;
        return 404;
    }
    location ^~ /lua/ {
        deny all;
        return 404;
    }
    location ^~ /backend/ {
        deny all;
        return 404;
    }
    location ^~ /core/ {
        deny all;
        return 404;
    }
    location ^~ /bootstrap/ {
        deny all;
        return 404;
    }
    location ^~ /tests/ {
        deny all;
        return 404;
    }
    location ^~ /docs/ {
        deny all;
        return 404;
    }
    location ^~ /headless/ {
        deny all;
        return 404;
    }
    location ^~ /cli/ {
        deny all;
        return 404;
    }
    location ~* ^/(nurl|nurl-key)(\.php)?(/|$) {
        deny all;
        return 404;
    }
    location ^~ /internal/ {
        deny all;
        return 404;
    }

    # ★ WebUI API 单入口
    location ^~ /api/ {
        limit_req zone=api burst=5 nodelay;
        limit_req_status 429;
        add_header Cache-Control "no-store" always;
        include fastcgi_params;
        fastcgi_pass $su_php_fpm;
        fastcgi_param SCRIPT_FILENAME /www/wwwroot/s.example.com/api/api.php;
        fastcgi_param SCRIPT_NAME /api.php;
        fastcgi_param HTTP_PROXY "";
    }

    # ★ 无头链路单入口
    location ^~ /headless/api/ {
        limit_req zone=headless burst=10 nodelay;
        limit_req_status 429;
        add_header Cache-Control "no-store" always;
        include fastcgi_params;
        fastcgi_pass $su_php_fpm;
        fastcgi_param SCRIPT_FILENAME /www/wwwroot/s.example.com/headless/headless.php;
        fastcgi_param SCRIPT_NAME /headless.php;
        fastcgi_param HTTP_PROXY "";
    }

    # ★ 一次性数据加载（惰性触发）
    access_by_lua_block {
        package.path = "/www/wwwroot/s.example.com/backend/lua/?.lua;;;" .. package.path
        local init = require "shorturl.init"
        init.init()
    }

    # ★ 短链跳转
    location ~* "^/([0-9a-z-]{1,5})$" {
        limit_req zone=redirect_burst burst=10 nodelay;
        limit_except GET { deny all; }
        content_by_lua_block {
            package.path = "/www/wwwroot/s.example.com/backend/lua/?.lua;;;" .. package.path
            local redirect = require "shorturl.redirect"
            redirect.go()
        }
    }

    # 宝塔默认配置（保留）
    location ~ ^/(\.user.ini|\.htaccess|\.git|\.env|\.svn|\.project|LICENSE|README\.md|修复指南\.md|docker-compose\.yml)
    {
        return 404;
    }
    location ~ .*\.(gif|jpg|jpeg|png|bmp|swf)$
    {
        expires      30d;
        access_log off;
    }
    location ~ .*\.(js|css)$
    {
        expires      12h;
        access_log off;
    }
    access_log  /www/wwwlogs/s.example.com.log;
    error_log  /www/wwwlogs/s.example.com.error.log;
}
# ══════════════════════[END]═════════════════════════════
```

---

## 三、宝塔部署步骤

### 1. 创建网站

1. 进入 **网站管理 → 添加站点**
2. 填写域名
3. 选择 PHP 版本（8.1+）
4. 选择根目录（如 `/www/wwwroot/s.example.com`）
5. 确认创建

### 2. 上传项目文件

1. 进入 **文件管理**
2. 进入网站根目录（如 `/www/wwwroot/s.example.com`）
3. 上传 `URL_PHP_v2.0.3` 中的所有文件

### 3. 修改配置

#### 3.1 修改 OpenResty/Nginx 主配置

1. 进入 **软件商店 → OpenResty/Nginx → 设置 → 配置修改**
2. 在 `http {}` 块内添加共享字典和内部 API 配置

#### 3.2 修改站点配置

1. 进入 **网站管理 → 对应网站 → 设置 → 配置文件**
2. **删除**原有的 `server {}` 块
3. **粘贴**上面的完整配置

### 4. 确认 PHP-FPM Socket 路径

宝塔中 PHP-FPM 通常使用 Unix Socket，路径格式：
```
unix:/tmp/php-cgi-81.sock  # PHP 8.1
unix:/tmp/php-cgi-80.sock  # PHP 8.0
unix:/tmp/php-cgi-74.sock  # PHP 7.4
```

或者使用 TCP：
```
127.0.0.1:9000
```

查看实际路径：
1. 进入 **软件商店 → PHP 管理 → 设置 → 配置修改**
2. 查找 `listen` 配置项

### 5. 初始化数据

进入 **网站管理 → 对应网站 → 终端**，执行：

```bash
cd /www/wwwroot/s.example.com
php cli/nurl -new
chown -R www:www backend/data/
chmod 750 backend/data/
chmod 600 backend/data/internal_token
```

### 6. 重载配置

1. 进入 **软件商店 → OpenResty/Nginx → 重载配置**
2. 或进入 **网站管理 → 对应网站 → 重载配置**

### 7. 验证服务

```bash
curl http://s.example.com/api/ping
```

---

## 四、路径说明（宝塔）

| 路径 | 说明 |
|------|------|
| `/www/wwwroot/s.example.com/` | 项目根目录 |
| `/www/wwwroot/s.example.com/backend/data/` | 数据存储目录 |
| `/www/wwwroot/s.example.com/backend/lua/` | Lua 脚本目录 |
| `/www/wwwroot/s.example.com/api/api.php` | WebUI API 入口 |
| `/www/wwwroot/s.example.com/headless/headless.php` | Headless API 入口 |
| `/www/server/nginx/` | Nginx 安装目录 |
| `/www/server/php/` | PHP 安装目录 |
| `/www/wwwlogs/` | 日志目录 |

---

## 五、PHP 配置

编辑 `core/config.php`：

```php
'domain' => 'http://s.example.com',  # SSL 启用后改为 https://
'panel_origin' => '',
'panel_enabled' => true,
'internal_host' => 'http://127.0.0.1:18500',  # 本机回环
'cold_fallback' => false,  # 严格模式：热存储未确认时返回 503（修复内部通信后可按需改为 true）
```

---

## 六、常见问题

### 1. 502 错误

**原因**：PHP-FPM Socket 路径不正确

**解决**：
```bash
# 检查 PHP-FPM 是否运行
systemctl status php-fpm-81

# 检查 Socket 文件是否存在
ls -la /tmp/php-cgi-81.sock
```

### 2. 热存储不可用 (503)

**原因**：内部 API 通信失败

**解决**：
1. 检查内部令牌路径是否正确
2. 确认内部令牌文件存在
3. 检查 OpenResty 错误日志

### 3. 430 错误

**原因**：数据文件权限问题

**解决**：
```bash
# 设置目录和文件权限（勿使用 chmod -R 755，会使 internal_token 全局可读）
chown -R www:www /www/wwwroot/s.example.com/backend/data/
chmod 750 /www/wwwroot/s.example.com/backend/data/
chmod 640 /www/wwwroot/s.example.com/backend/data/perm.json
chmod 640 /www/wwwroot/s.example.com/backend/data/temp.json
chmod 640 /www/wwwroot/s.example.com/backend/data/keys.json
chmod 600 /www/wwwroot/s.example.com/backend/data/internal_token
```

### 4. Lua 模块找不到

**原因**：Lua 路径配置错误

**解决**：
1. 检查 `package.path` 配置
2. 确认 Lua 文件存在
3. 检查文件权限

---

## 七、SSL 配置（可选）

如果需要配置 SSL，可以：

1. 在宝塔面板中申请 Let's Encrypt 证书
2. 或上传自己的证书
3. 修改站点配置，取消注释 SSL 相关行
4. 修改 `core/config.php` 的 `domain` 为实际域名（SSL 启用后改为 `https://`）
