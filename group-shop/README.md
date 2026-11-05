# Group Shop

> 当前 muti-storefront-plugin 本地已升级 0.5.10 单站多品牌并关闭 Multisite；生产未发布。当前状态和专用本地启动／迁移／回滚请读 [插件 HANDOFF](plugin/muti-storefront-plugin/docs/HANDOFF.md) 与 [OPERATIONS](plugin/muti-storefront-plugin/docs/OPERATIONS.md)。下文为原项目／历史部署说明，不是本次部署授权。

> 2026-11-06 本地插件已更名为 muti-storefront-plugin 0.5.10 与 product-addon-plugin 3.8.5；trovesia-channel 保持禁用、源码保留。目录／入口／后台／挂载及维护引用已更新，生产未发布。
> 2026-11-06 当前本地为72件Chanel公共商品＋72件主店销售版本；七品牌和后台账号保留。0.5.9已完成原生SEO隔离本地验收，CSS／JS／图片继续共用。此前重置时的0品牌版本是历史基线，当前状态与私有备份见插件HANDOFF／OPERATIONS。


`group-shop` 是一套独立的 WordPress + Nginx + MariaDB + Redis 商店环境。
它与 `13799-shop` 使用不同的 Compose 项目名、宿主机端口、named
volumes、数据库凭据与 Redis 键前缀，可以在同一台服务器上同时运行。

站点自定义代码位于：

```text
theme/trovesia/                 页面模板、样式和前端交互
plugin/product-addon-plugin/         站点业务功能和 WordPress hooks
```

这两个目录独立挂载到 WordPress 容器，本地修改后可直接刷新页面验证。

## 默认端口

| 用途 | 地址 |
|---|---|
| WordPress HTTP（供主机 Nginx 反向代理） | `127.0.0.1:8086` |
| MariaDB（供 SSH Tunnel） | `127.0.0.1:3312` |

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
插件 → 已安装的插件 → product-addon-plugin → 启用
```

默认仅绑定 `127.0.0.1:8086`。在服务器本机上检查：

```bash
curl -I http://127.0.0.1:8086/
```

如果确实需要临时从外部直连，可将 `HTTP_BIND_IP` 改成 `0.0.0.0`，
但正式环境建议继续使用 `127.0.0.1` 并由主机 Nginx 终止 HTTPS。

## 域名与 HTTPS

站点使用 `https://www.animecats.online`，部署到 `root@contabo2:/root/wordpress-sites/group-shop`。
主机配置为 `nginx/host/www.animecats.online.conf`，独立证书位于服务器 `/root/ssl/group-shop/`，由已有 acme.sh 自动续期。

本店完整复制 Trovesia 的数据库、媒体和第三方插件，内部主题/插件 slug 与 PHP 命名空间继续保留 `trovesia`，页面名称使用 Group Shop。
数据库、Compose 项目、Redis 前缀、端口、备份与安全监控使用独立配置。
原站客服邮箱、支付及 SMTP 账户按完整复制要求保留。支付平台的域名和 webhook 仍需独立核验。

复制时保留历史订单和账号，重新生成本站 WordPress 密钥并重新加密 FluentSMTP。
迁移阶段曾暂停 cron；核对 15 项待执行任务均为周期维护、无待付款订单后，已恢复正常计划任务。

## 多店铺插件（新对话先读）

新功能入口为 [muti-storefront-plugin](plugin/muti-storefront-plugin/README.md)。接手时先阅读 [当前状态](plugin/muti-storefront-plugin/docs/HANDOFF.md)、[架构](plugin/muti-storefront-plugin/docs/ARCHITECTURE.md) 与 [变更记录](plugin/muti-storefront-plugin/CHANGELOG.md)。功能和注意事项变更必须同步更新文档，详见根目录 AGENTS.md。

底层为 WordPress Multisite，共享用户与默认地址。0.2.1 已增加店铺 Logo 原生媒体选择和搜索引擎收录开关，保留统一分类模板、分店覆盖与规则集合，各店商品、评论和订单独立；商品和变体不管理库存、始终有货，批量库存已移除。第二店为 https://test.animecats.online/（禁止收录、邮件关闭、商品仍为草稿）。支付待定，详细上线证据和限制以 HANDOFF 为准。

## 运维

```bash
./scripts/deploy-all.sh
ssh root@contabo2 'cd /root/wordpress-sites/group-shop && bash scripts/security-baseline.sh check'
ssh root@contabo2 'cd /root/wordpress-sites/group-shop && bash scripts/security-backup.sh'
```

仅运行自定义代码部署脚本时不会替换数据库或媒体。完整私有备份保存到 `/root/group-shop-backups/`。
详细部署与验收记录见 `DEPLOYMENT.md`。

本地0.5.8：商品展示支持公共继承／品牌覆盖，add-ons保留并限定本店独立规则。最新验收与当前数据见插件HANDOFF。
