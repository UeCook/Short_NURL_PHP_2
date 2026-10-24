# Docker/OpenResty 部署（推荐）

## 适用场景
- 使用 Docker Compose 部署
- OpenResty 和 PHP-FPM 在同一个 docker-compose 项目中
- 使用 Docker 网络进行容器间通信

本项目的公共运行时依赖是 **Nginx + ngx_http_lua_module + PHP-FPM**。Docker/OpenResty
只是推荐的打包方式；1Panel 和宝塔请分别使用 `2-1panel.md`、`3-baota.md`，不要把本文件
中的容器名、路径或 Docker 网络地址复制到宿主机部署。

## 前置条件
1. 已安装 Docker 和 docker-compose
2. 已创建 docker-compose.yml 文件
3. 已配置好网络和卷挂载

---

## 一、Nginx 主配置

将以下内容添加到 OpenResty 容器的 `nginx.conf` 文件中的 `http {}` 块内：

**同时需要添加 PHP-FPM 的 upstream 声明**（解决变量形式 `fastcgi_pass` 需要 resolver 的问题）：

```nginx
# 在 http {} 块内、server {} 块之前添加
upstream su_php {
    server php-fpm:9000;
}
```

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
    # 内部 Lua API（仅本地 + Docker bridge 访问）
    # ═══════════════════════════════════════════════════
    server {
        listen 18500;
        server_name _;
        # 注：18500 在容器内部监听，docker-compose 未发布该端口到宿主机，
        # 仅容器间 Docker 网络可达。如需从宿主机访问，添加 allow 宿主机 IP。

        # 必须与 core/config.php 的 internal_token_path 指向同一文件
        set $su_internal_token_path "/var/www/html/backend/data/internal_token";

        location /internal/ {
            # 访问控制：允许本地回环 + Docker bridge 网段
            # 请将 172.18.0.0/16 替换为你实际的 Docker 网桥子网（切勿使用 172.16.0.0/12 等过宽范围）
            allow 127.0.0.1;
            allow ::1;
            allow 172.18.0.0/16;
            deny all;
            limit_except GET POST { deny all; }

            content_by_lua_block {
                package.path = "/var/www/html/backend/lua/?.lua;;;" .. package.path
                local internal = require "shorturl.internal"
                internal.dispatch()
            }
        }
    }
# ═══════════════════════[END]═════════════════════════
```

---

## 二、站点 Nginx 配置

创建站点配置文件，通常放在 `/etc/nginx/conf.d/` 或 `/etc/nginx/sites-available/` 目录：

```nginx
# ═══════════════════════════════════════════════════
# ShortURL 站点配置
# ═══════════════════════════════════════════════════

server {
    listen 80;
    server_name your-domain.com;  # 替换为你的域名

    root /var/www/html;
    index index.html;

    # ★ 业务参数
    set $su_php_fpm          "php-fpm:9000";   # PHP-FPM 地址（按部署环境修改）
    # Docker Compose 部署：php-fpm:9000（服务名）或 172.18.0.1:9000（网桥网关）
    # 1Panel 部署（network_mode:host）：127.0.0.1:9000
    set $su_tz_offset        "+08:00";
    set $su_perm_path        "/var/www/html/backend/data/perm.json";
    set $su_temp_path        "/var/www/html/backend/data/temp.json";
    set $su_expire_interval  3600;
    set $su_redirect_code    302;

    # ★ 安全响应头
    add_header X-Content-Type-Options  "nosniff"          always;
    add_header X-Frame-Options         "DENY"             always;
    add_header Referrer-Policy         "no-referrer"      always;
    add_header Content-Security-Policy "default-src 'self'; frame-ancestors 'none'" always;
    add_header Permissions-Policy      "geolocation=(), camera=(), microphone=()" always;

    # ★ 静态资源
    location / {
        try_files $uri $uri/ =404;
    }

    # ★ 屏蔽敏感目录和文件
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
        fastcgi_pass su_php;
        fastcgi_param SCRIPT_FILENAME /var/www/html/api/api.php;
        fastcgi_param SCRIPT_NAME /api.php;
        fastcgi_param HTTP_PROXY "";
    }

    # ★ 无头链路单入口
    location ^~ /headless/api/ {
        limit_req zone=headless burst=10 nodelay;
        limit_req_status 429;
        add_header Cache-Control "no-store" always;
        include fastcgi_params;
        fastcgi_pass su_php;
        fastcgi_param SCRIPT_FILENAME /var/www/html/headless/headless.php;
        fastcgi_param SCRIPT_NAME /headless.php;
        fastcgi_param HTTP_PROXY "";
    }

    # ★ 一次性数据加载（惰性触发）
    access_by_lua_block {
        package.path = "/var/www/html/backend/lua/?.lua;;;" .. package.path
        local init = require "shorturl.init"
        init.init()
    }

    # ★ 短链跳转
    location ~* "^/([0-9a-z-]{1,5})$" {
        limit_req zone=redirect_burst burst=10 nodelay;
        limit_except GET { deny all; }
        content_by_lua_block {
            package.path = "/var/www/html/backend/lua/?.lua;;;" .. package.path
            local redirect = require "shorturl.redirect"
            redirect.go()
        }
    }
}
# ══════════════════════[END]═════════════════════════════
```

---

## 三、docker-compose.yml 示例

```yaml
version: '3.8'

services:
  openresty:
    image: openresty/openresty:alpine
    container_name: shorturl-openresty
    ports:
      - "80:80"
      - "443:443"
    volumes:
      - ./nginx/nginx.conf:/usr/local/openresty/nginx/conf/nginx.conf
      - ./nginx/conf.d:/etc/nginx/conf.d
      - ./:/var/www/html
      - ./nginx/logs:/var/log/nginx
    depends_on:
      - php-fpm
    networks:
      - shorturl-network

  php-fpm:
    build:
      context: .
      dockerfile: Dockerfile.php
    container_name: shorturl-php-fpm
    volumes:
      - ./:/var/www/html
    networks:
      - shorturl-network

networks:
  shorturl-network:
    driver: bridge
```

---

## 四、部署步骤

### 1. 创建项目目录
```bash
mkdir shorturl && cd shorturl
```

### 2. 复制项目文件
```bash
cp -r /path/to/URL_PHP_v2.0.3/* .
```

### 3. 创建配置文件
- 创建 `docker-compose.yml`
- 创建 `nginx/nginx.conf`
- 创建 `nginx/conf.d/shorturl.conf`

### 4. 修改配置
- 替换 `your-domain.com` 为实际域名
- 确认 PHP-FPM 服务名正确

### 5. 启动服务
```bash
docker-compose up -d
```

### 6. 初始化数据
```bash
docker exec shorturl-php-fpm php cli/nurl -new
```

### 7. 验证服务
```bash
curl http://your-domain.com/api/ping
```

---

## 五、路径说明

| 容器内路径 | 说明 |
|-----------|------|
| `/var/www/html/` | 项目根目录 |
| `/var/www/html/backend/data/` | 数据存储目录 |
| `/var/www/html/backend/lua/` | Lua 脚本目录 |
| `/var/www/html/api/api.php` | WebUI API 入口 |
| `/var/www/html/headless/headless.php` | Headless API 入口 |

---

## 七、PHP 扩展（Docker）

`php:8.3-fpm-alpine` 镜像默认**不包含 ext-curl**，而 `InternalApi` 依赖 curl 扩展。请创建 `Dockerfile.php`：

```dockerfile
FROM php:8.3-fpm-alpine
RUN apk add --no-cache curl-dev \
    && docker-php-ext-install curl
```

然后在 `docker-compose.yml` 中使用 `build` 而非 `image`（见上方示例）。

编辑 `core/config.php`：

```php
'domain' => 'https://your-domain.com',
'panel_origin' => '',
'panel_enabled' => true,
'internal_host' => 'http://openresty:18500',  # 使用 Docker 服务名
'cold_fallback' => false,
```
