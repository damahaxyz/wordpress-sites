# Tikool Shop

`tikool-shop` 是一套面向美国宠物定制产品的独立 WordPress + Nginx + MariaDB + Redis 商店环境。
它与其他项目使用不同的 Compose 项目名、宿主机端口、named
volumes、数据库凭据与 Redis 键前缀，可以在同一台服务器上同时运行。

站点自定义代码位于：

```text
theme/tikool/                 页面模板、样式和前端交互
plugin/tikool-plugin/         站点业务功能和 WordPress hooks
plugin/tikool-ai/             AI 服务配置、提示词模板和服务端调用接口
plugin/tikool-memory-page/    NFC 宠物纪念页、客户编辑器与 GitHub 发布
```

这些目录独立挂载到 WordPress 容器，本地修改后可直接刷新页面验证。

## 默认端口

| 用途 | 地址 |
|---|---|
| WordPress HTTP（供主机 Nginx 反向代理） | `127.0.0.1:8085` |
| MariaDB（供 SSH Tunnel） | `127.0.0.1:3311` |

这两个端口与现有站点使用的 `8080`–`8084` 和 `3306`–`3310` 错开。

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
外观 → 主题 → Tikool Shop → 启用
插件 → 已安装的插件 → Tikool Shop Plugin → 启用
插件 → 已安装的插件 → Tikool AI → 启用
插件 → 已安装的插件 → Tikool Memory Page → 启用
```

启用后在 **设置 → Tikool AI** 配置方舟 API Key、模型和功能提示词。密钥保存在
WordPress 数据库中，不会回显到后台页面或发送给浏览器端代码。

默认仅绑定 `127.0.0.1:8085`。在服务器本机上检查：

```bash
curl -I http://127.0.0.1:8085/
```

如果确实需要临时从外部直连，可将 `HTTP_BIND_IP` 改成 `0.0.0.0`，
但正式环境建议继续使用 `127.0.0.1` 并由主机 Nginx 终止 HTTPS。

## 域名与 HTTPS

默认站点地址为 `https://www.tikool.com`，`https://tikool.com` 会重定向到
`www`。主机 Nginx 配置位于 `nginx/host/www.tikool.com.conf`，反向代理
上游为：

```nginx
proxy_pass http://127.0.0.1:8085;
```

部署前请将 Tikool 自己的 Cloudflare Origin Certificate 安装到主机 Nginx
配置引用的 `/etc/nginx/ssl/www.tikool.com.pem` 和
`/etc/nginx/ssl/www.tikool.com.key`。私钥不得提交 Git 或写入聊天内容。

在 WooCommerce 商品编辑页启用 **Require pet photo** 后，商品页会要求顾客填写
宠物名字并上传一张照片。该照片保存到受 Nginx 保护的订单目录，只能在对应
WooCommerce 订单后台以管理员权限下载。

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
