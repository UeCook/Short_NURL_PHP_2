# 1Panel 面板部署 - Nginx 配置

本项目兼容带 `ngx_http_lua_module` 的 Nginx，也兼容 OpenResty。Docker/OpenResty 是推荐配置；
1Panel 部署使用宿主机 PHP-FPM 的 Unix Socket 或 TCP 监听，不依赖 Docker 服务名。

---

## 一、Nginx 主配置

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
        set $su_internal_token_path "/www/sites/【你的域名】/index/backend/data/internal_token";

        location /internal/ {
            # 访问控制：允许本地回环 + Docker bridge 网段
            # 请将 172.18.0.0/16 替换为你实际的 Docker 网桥子网（切勿使用 172.16.0.0/12 等过宽范围）
            allow 127.0.0.1;
            allow ::1;
            allow 172.18.0.0/16;
            deny all;
            limit_except GET POST { deny all; }

            content_by_lua_block {
                package.path = "/www/sites/【你的域名】/index/backend/lua/?.lua;;;" .. package.path
                local internal = require "shorturl.internal"
                internal.dispatch()
            }
        }
    }
# ═══════════════════════[END]═════════════════════════
```

---

## 二、站点 Nginx 配置

```nginx
server {
    listen 80;  # Replace with your port 
    server_name your-domain.com;  # Replace with your domain 
    index index.php index.html index.htm default.php default.htm default.html; 
    access_log /www/sites/【你的域名】/log/access.log main; 
    error_log /www/sites/【你的域名】/log/error.log; 

    # ═══════════════════════════════════════════════════
    # ShortURL 站点配置
    # ═══════════════════════════════════════════════════

    # ★ 业务参数
    set $su_php_fpm          "127.0.0.1:9000";  # PHP-FPM 地址（按部署环境修改）
    # 场景 A：OpenResty network_mode:host + PHP 端口映射 127.0.0.1:9000:9000
    #   → 用 127.0.0.1:9000（推荐，更安全）
    # 场景 B：OpenResty bridge 网络 + PHP 端口映射 9000:9000
    #   → 用 172.18.0.1:9000（Docker 网桥网关）或 PHP 容器名:9000
    set $su_tz_offset        "+08:00";
    set $su_perm_path        "/www/sites/【你的域名】/index/backend/data/perm.json";
    set $su_temp_path        "/www/sites/【你的域名】/index/backend/data/temp.json";
    set $su_expire_interval  3600;
    set $su_redirect_code    302;

    # ★ 安全响应头
    add_header X-Content-Type-Options  "nosniff"          always;
    add_header X-Frame-Options         "DENY"             always;
    add_header Referrer-Policy         "no-referrer"      always;
    add_header Content-Security-Policy "default-src 'self'; frame-ancestors 'none'" always;
    add_header Permissions-Policy      "geolocation=(), camera=(), microphone=()" always;

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
        fastcgi_param SCRIPT_FILENAME /www/sites/【你的域名】/index/api/api.php;
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
        fastcgi_param SCRIPT_FILENAME /www/sites/【你的域名】/index/headless/headless.php;
        fastcgi_param SCRIPT_NAME /headless.php;
        fastcgi_param HTTP_PROXY "";
    }

    # ★ 一次性数据加载（惰性触发）
    access_by_lua_block {
        package.path = "/www/sites/【你的域名】/index/backend/lua/?.lua;;;" .. package.path
        local init = require "shorturl.init"
        init.init()
    }

    # ★ 短链跳转
    location ~* "^/([0-9a-z-]{1,5})$" {
        limit_req zone=redirect_burst burst=10 nodelay;
        limit_except GET { deny all; }
        content_by_lua_block {
            package.path = "/www/sites/【你的域名】/index/backend/lua/?.lua;;;" .. package.path
            local redirect = require "shorturl.redirect"
            redirect.go()
        }
    }

    # 1Panel 默认配置（保留）
    location ~ ^/(\.user.ini|\.htaccess|\.git|\.env|\.svn|\.project|LICENSE|README\.md|修复指南\.md|docker-compose\.yml) {
        return 404; 
    }
    location ^~ /.well-known/acme-challenge {
        allow all; 
        root /usr/share/nginx/html; 
    }
    if ( $uri ~ "^/\.well-known/.*\.(php|jsp|py|js|css|lua|ts|go|zip|tar\.gz|rar|7z|sql|bak)$" ) {
        return 403; 
    }
    error_page 404 /404.html; 
    root /www/sites/【你的域名】/index; 
}
# ══════════════════════[END]═════════════════════════════
```
