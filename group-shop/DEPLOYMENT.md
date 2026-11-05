# Group Shop 部署记录

> 当前 muti-storefront-plugin 本地已升级 0.5.10 单站多品牌并关闭 Multisite；生产未发布。当前状态和专用本地启动／迁移／回滚请读 [插件 HANDOFF](plugin/muti-storefront-plugin/docs/HANDOFF.md) 与 [OPERATIONS](plugin/muti-storefront-plugin/docs/OPERATIONS.md)。下文为原项目／历史部署说明，不是本次部署授权。

> 2026-11-06 本地插件已更名为 muti-storefront-plugin 0.5.10 与 product-addon-plugin 3.8.5；trovesia-channel 保持禁用、源码保留。目录／入口／后台／挂载及维护引用已更新，生产未发布。

部署日期：2026-11-02 至 2026-11-03，Asia/Taipei。

## 访问与位置

- 商店：https://www.animecats.online/
- 管理登录：https://www.animecats.online/wp-login.php
- 服务器：`root@contabo2`，IP `94.72.127.120`
- 部署目录：`/root/wordpress-sites/group-shop`
- Compose 项目：`group-shop`
- HTTP 上游：`127.0.0.1:8086`
- MariaDB：`127.0.0.1:3312`，数据库与用户 `wordpress_group`
- Redis：独立容器，键前缀 `group-shop:`
- 主机 Nginx：`/etc/nginx/conf.d/group-shop.conf`
- 证书：`/root/ssl/group-shop/fullchain.pem`、`privkey.pem`
- 证书由 acme.sh 签发并加入已有续期任务，包含 `www.animecats.online`。

## 完整复制

按站主明确选择，复制商品、媒体、页面、全部用户、客户、订单、渠道、第三方插件和店铺设置。
原站私有备份：`root@site:/root/trovesia-backups/20261102T154055Z`。
目标收到的原始快照：`/root/group-shop-import/20261102T154055Z`。
数据库、wp-content、wp-config、部署配置四个归档均通过原始 SHA-256 校验。

初次本机中转连接中断后，使用限定原站 IP、限定备份接收路径的临时 SSH 授权直接续传；完成后已删除授权和源站临时密钥。
敏感备份未复制到本地源码仓库。新店未沿用原站 `.env` 或原站证书。

新数据库凭据及 WordPress 密钥独立生成。管理员和客户的账号密码、历史订单保留。
FluentSMTP 的四项加密值已重新加密并通过验证，没有发送测试邮件。
按完整复制要求保留原客服邮箱 `service@trovesia.com`、SMTP 服务及支付账户配置。

对序列化数据库执行安全域名替换，共 267 处，将站内地址切换为新域名。
页面标题、页眉页脚及邮件品牌名称使用 Group Shop。
PHP 命名空间、主题 slug、插件 slug、商品 SKU 保留，避免破坏历史配置关联。
渠道短链改用本站 canonical www 地址，不依赖根域名解析。

## 验证结果

- 四个容器均 healthy；源站及 Cloudflare 公网 HTTPS 均为 200。
- 域名调整前后 22 张核心表的记录数保持一致：6 个账号、4 笔 HPOS 订单、2 个渠道。
- 5 个发布商品、22 个发布变体、82 个媒体记录、13 个发布页面完整保留。
- 40 个主题/插件/安全 PHP 文件语法检查通过，shell/Python 语法通过。
- 12 项监控单元测试及部署失败阻断/成功流程门禁通过。
- 13 项渠道短链/归因/缓存回归通过；临时测试记录已清理。
- 4 项商品默认变体回归通过，无持久商品修改。
- 浏览器实测单台泡泡机加入购物车，数量 1、金额 $29.90，随后移除并确认购物车为空。
- 未提交结账、未创建测试订单、未扣款。
- HTTPS 请求上下文下 Stripe 与 PayPal 主网关为 enabled/available；购物车显示 PayPal、Stripe Link 和 Google Pay。
- 文件权限、只读代码挂载、PHP 执行限制、插件允许名单、邮件加密、动态 PHP 首页及被禁止的 PHP 路由检查通过。
- 最终完整安全检查：`security-reports/check-20261102T160156Z-951932.log`。
- 服务器原有 manga-shop、tikool-shop 及其他服务未替换。

## 当前保留的限制

迁移期间先设置 `DISABLE_WP_CRON=true`。上线前逐项检查 15 个 pending Action Scheduler hook 及全部 WP cron hook，均为清理、统计、缓存、更新检查及周期维护。四笔历史订单中三笔 processing、一笔 cancelled，无待付款订单。检查后恢复 `DISABLE_WP_CRON=false`，让新店正常执行计划任务。未主动重放历史任务或发送测试邮件。

支付账号和凭据虽完整保留，Stripe/PayPal 平台上的新域名授权、webhook 注册与支付到账/退款全过程未验证。
数据库 URL 替换不代表支付平台已建立新端点，不能据此宣布收款验收完成。
Stripe 本站回调地址为 `https://www.animecats.online/?wc-api=wc_stripe`。

Cloudflare 使用新域名所在 zone 的现有设置。原站独立配置的管理员访问规则、WAF 及其他 Cloudflare 控制台规则不会随 WordPress 数据库复制，尚未核验它们在新 zone 中的等效配置。
主机仅信任 Cloudflare 官方 22 段 IP 的 `CF-Connecting-IP`，防止普通直连伪造访客 IP。

独立监控服务 `group-shop-security-watch.service` 已启用，健康检测正常；邮件告警默认关闭，仅记录本机日志。
如要启用邮件告警，在 systemd 服务设置 `GROUP_SHOP_ALERTS_ENABLED=1` 后重启服务。
尚未进行真实邮件投递测试。

## 备份与后续部署

部署后新店私有完整备份：`/root/group-shop-backups/20261102T155650Z`，已验证归档与 SHA-256；最终恢复 cron 的 Compose 配置另存为同目录 `compose-final-cron-enabled.yaml` 并附独立校验文件。

```bash
# 自定义主题与插件更新，默认只部署到 group-shop。
./scripts/deploy-all.sh

# 安全检查。
ssh root@contabo2 'cd /root/wordpress-sites/group-shop && bash scripts/security-baseline.sh check </dev/null'

# 完整备份。
ssh root@contabo2 'cd /root/wordpress-sites/group-shop && bash scripts/security-backup.sh </dev/null'
```

主机 Nginx 当前由现有 master 进程运行，配置更新使用 `nginx -t && nginx -s reload`，不使用未激活的 `nginx.service`。

## muti-storefront-plugin 0.1.0（2026-11-03）

已转换为原生 Multisite，网络启用多店铺插件、WooCommerce 与 FluentSMTP。后台入口 `/wp-admin/admin.php?page=muti-storefront-plugin`。共享用户、密码和默认地址；商品、库存、评论、订单按店铺独立。支付集成待定，旧独立 Trovesia 不会自动加入网络。

迁移前完整备份 `/root/group-shop-backups/20261102T171948Z`，快速旧配置 `deploy-backups/gsm-v010-pre-multisite/`。演示店 `/demo-store/`（blog 2）禁止收录、邮件关闭，包含草稿商品 18（来源主商品 175）。原 6 用户、4 订单保留，未发送实际邮件或执行付款。

新对话请先读 `AGENTS.md`、`plugin/muti-storefront-plugin/docs/HANDOFF.md`；功能、限制或部署变更必须更新文档。详细验收和回滚以插件 HANDOFF/OPERATIONS 为准。

### 后台路由修复（2026-11-03）

用户报告 `/wp-admin/` 循环重定向。根因为子站 PHP 重写误匹配实际存在的 `/wp-admin/index.php`，插件页 `admin.php` 也受影响。将前缀重写置于 `if (!-e $request_filename)` 内，并同步 QA Nginx 配置。旧配置备份 `deploy-backups/gsm-admin-route-fix/default.conf`；由于单文件挂载固定 inode，重建了 group-shop 的 nginx 容器使新配置生效，未重启其他站点。

新增 `scripts/group-shop-manager/check-http-routes.py` 检查真实 HTTP 后台入口、登录落点、CSS 和直接 PHP 阻断；CLI 页面渲染不能代表 HTTP 路由验收。公网浏览器确认 www 和裸域名的后台都可正常进入登录页。最终回归和安全基线报告见 `qa/group-shop-manager/REDIRECT-FIX.md`。

### 第二店铺 test.animecats.online（2026-11-03）

按用户要求将 blog 2 原位映射到 `https://test.animecats.online/`，编号、UUID、草稿商品、noindex 和关闭邮件保持。新域名独立宿主 Nginx 配置 `/etc/nginx/conf.d/group-shop-test.conf`，源码 `nginx/host/test.animecats.online.conf`；Let's Encrypt 证书 `/root/ssl/group-shop-test/`，acme.sh HTTP 验证及续期安装已设置。主店 `/demo-store/` 301 到新域名，保留余下路径和查询参数；主网络域名不改。仅在 wp_2_ 表替换 3 处旧内容链接。

变更前完整备份 `/root/group-shop-backups/20261102T175306Z`；宿主配置备份 `deploy-backups/gsm-store2-domain/`。公网首页、账户页和登录入口已验证。主店仍是 `https://www.animecats.online/`，统一管理入口不变；账号共享但不同域名分别登录。

## muti-storefront-plugin 0.2.0（2026-11-03）

已通过受控 manager-plugin 流程部署并网络启用；新增统一分类、分店展示覆盖、规则/手工商品集合和可视化分类/标签选择。按用户最新要求删除批量库存，主店 27 个、分店 3 个商品与变体已通过 CRUD 迁移为不管理数量、始终有货，原生 lookup 同步。商品名称/售价/发布状态、订单/用户计数与 UUID 对比保持。分店仍在 test.animecats.online，禁止收录、邮件关闭，商品草稿不变。

升级前完整备份 `/root/group-shop-backups/20261103T033820Z`；旧组件 `deploy-backups/group-shop-manager-20261103T033929Z.tar.gz`。迁移脚本 `scripts/group-shop-manager/upgrade-v020.php`，19 项安装验收通过；QA 120 项集成与 6 项 HTTP、浏览器 nonce 保存/排序验证通过。最终后台 HTTP 与安全证据见 `qa/group-shop-manager/RELEASE-v020.md`，新对话先读插件 HANDOFF。旧 0.1.0 安装器已版本封锁，不得再按旧路径创建演示店。回退库存与分类需恢复匹配数据库，停用/回退源码不会恢复旧数量。

## muti-storefront-plugin 0.2.1（2026-11-03）

店铺设置增加原生媒体 Logo 选择/上传/预览/移除和原生 blog_public 收录选项。Logo 保留店铺引用，兼容原生设置和主题切换。跨店媒体由原生处理器配合受控请求上下文完成，未支持的删除操作拒绝。已有收录、主题、Logo 升级时保持。

全量备份 `/root/group-shop-backups/20261103T043415Z`，旧组件 `deploy-backups/group-shop-manager-20261103T043802Z.tar.gz`。已有120项、店铺设置19项 QA和浏览器原生媒体上传/保存/移除通过；最终线上HTTP、配置对比与安全证据见 `qa/group-shop-manager/RELEASE-v021.md`。其他原生后台重构仍为规划。

## 当前发布冻结与本地 0.3.0（2026-11-03）

用户最新要求仅本地测试，全部修改完由用户明确通知后才发布；旧自动部署授权被覆盖。0.3.0 移除统一/店铺分类、保留本店集合的改动仅在 localhost:18087 验证，未上传到生产或远端 QA，未在线执行元数据清理。详见插件 HANDOFF 和 qa/group-shop-manager/v030-local/LOCAL-v030.md。

上一项店铺列表/弹窗/域名设置 0.2.2 已在新要求前开始并执行上传与服务更新；本轮只读确认线上插件为 0.2.2、四容器 healthy、部署标记清除，不能当作本轮 0.3.0 已上线。0.2.2 前完整备份 /root/group-shop-backups/20261103T055841Z。当前不追加任何线上发布。
