# Trovesia Shop

`trovesia-shop` 是一套独立的 WordPress + Nginx + MariaDB + Redis 商店环境。
它与 `aromamatrix-shop` 使用不同的 Compose 项目名、宿主机端口、named
volumes、数据库凭据与 Redis 键前缀，可以在同一台服务器上同时运行。

站点自定义代码位于：

```text
theme/trovesia/                 页面模板、样式和前端交互
plugin/trovesia-plugin/         站点业务功能和 WordPress hooks
```

这两个目录独立挂载到 WordPress 容器，本地修改后可直接刷新页面验证。

## 默认端口

| 用途 | 地址 |
|---|---|
| WordPress HTTP（供主机 Nginx 反向代理） | `127.0.0.1:8083` |
| MariaDB（供 SSH Tunnel） | `127.0.0.1:3309` |

这两个端口与现有站点使用的 `8080`–`8082` 和 `3306`–`3308` 错开。

## 启动

```bash
cp .env.example .env
chmod 600 .env
```

编辑 `.env`，至少替换：

```dotenv
MARIADB_PASSWORD=CHANGE_ME_DATABASE_PASSWORD
MARIADB_ROOT_PASSWORD=CHANGE_ME_ROOT_PASSWORD
```

可以生成两个不同的强密码：

```bash
openssl rand -base64 36
openssl rand -base64 36
```

然后启动：

```bash
docker compose config --quiet
docker compose pull
docker compose up -d --wait --wait-timeout 180
docker compose ps
```

首次启动后，在 WordPress 后台启用：

```text
外观 → 主题 → Trovesia Shop → 启用
插件 → 已安装的插件 → Trovesia Shop Plugin → 启用
```

默认仅绑定 `127.0.0.1:8083`。在服务器本机上检查：

```bash
curl -I http://127.0.0.1:8083/
```

如果确实需要临时从外部直连，可将 `HTTP_BIND_IP` 改成 `0.0.0.0`，
但正式环境建议继续使用 `127.0.0.1` 并由主机 Nginx 终止 HTTPS。

## 域名与 HTTPS

正式域名为 `https://www.trovesia.com`，`https://trovesia.com` 会重定向到
`www`。主机 Nginx 配置位于 `nginx/host/www.trovesia.com.conf`，反向代理
上游为：

```nginx
proxy_pass http://127.0.0.1:8083;
```

部署前请将 Trovesia 自己的 Cloudflare Origin Certificate 安装到主机 Nginx
配置引用的 `/etc/nginx/ssl/www.trovesia.com.pem` 和
`/etc/nginx/ssl/www.trovesia.com.key`。私钥不得提交 Git 或写入聊天内容。

## 自定义开发与部署

主题和插件的文件职责、PHP 命名规则与一键部署方式见
[`DEVELOPMENT.md`](DEVELOPMENT.md)。

## 备份

```bash
./scripts/backup.sh
```

备份会写入本目录的 `backups/`，不会与其他 shop 混用。脚本会导出
MariaDB、归档 `wp-content` 并生成 SHA-256 校验文件。

## 常用命令

```bash
docker compose logs -f --tail=100
docker compose restart
docker compose down
```

`docker compose down` 会保留 named volumes。请勿执行 `docker compose down -v`，
因为 `-v` 会删除该 shop 的 WordPress 文件与数据库。
