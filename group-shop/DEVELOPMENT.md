# Group Shop 自定义开发

> 当前 muti-storefront-plugin 本地已升级 0.5.10 单站多品牌并关闭 Multisite；生产未发布。当前状态和专用本地启动／迁移／回滚请读 [插件 HANDOFF](plugin/muti-storefront-plugin/docs/HANDOFF.md) 与 [OPERATIONS](plugin/muti-storefront-plugin/docs/OPERATIONS.md)。下文为原项目／历史部署说明，不是本次部署授权。

> 2026-11-06 本地插件已更名为 muti-storefront-plugin 0.5.10 与 product-addon-plugin 3.8.5；trovesia-channel 保持禁用、源码保留。目录／入口／后台／挂载及维护引用已更新，生产未发布。

多店铺插件开发先读 `AGENTS.md` 与 `plugin/muti-storefront-plugin/docs/HANDOFF.md`。新增功能、数据、配置、限制、权限或部署变更必须同时更新对应文档和 CHANGELOG。原有 Trovesia 主题及业务插件说明保留如下。

## 目录职责

```text
theme/trovesia/
├── functions.php          主题功能、菜单、资源和 WooCommerce 集成
├── front-page.php         首页结构
├── header.php / footer.php
├── page.php / single.php  内容页模板
├── woocommerce.php        WooCommerce 页面外层
├── style.css              全站样式
└── assets/                 额外 CSS 与 JavaScript

plugin/product-addon-plugin/
├── product-addon-plugin.php        插件入口与常量
├── includes/class-plugin.php
├── includes/class-site-experience.php
├── includes/class-catalog-migrator.php
├── includes/class-review-migrator.php
├── data/catalog.php
└── uninstall.php           卸载时清理插件自己的设置

plugin/muti-storefront-plugin/    多店铺中央管理，详见本插件 docs/

plugin/trovesia-channel/
├── trovesia-channel.php    渠道插件入口与 HPOS 兼容声明
├── includes/               渠道、短链、归因、后台与渠道门户
├── assets/portal.css       渠道门户样式
└── uninstall.php           默认保留归因数据的安全卸载逻辑
```

页面展示只放在主题中；产品字段、订单规则、短代码、REST API、
第三方服务和后台设置应放在插件中，避免换主题时丢失业务功能。

## 命名规则

因为 PHP 类、函数和常量名不能以数字开头，这个站点使用：

- 主题函数前缀：`trovesia_`
- 插件命名空间：`Trovesia\Plugin`
- 插件常量前缀：`TROVESIA_`
- 主题 text domain：`trovesia`
- 插件 text domain：`trovesia-plugin`

## 本地检查

```bash
find theme/trovesia plugin/product-addon-plugin plugin/trovesia-channel \
  -type f -name '*.php' -exec php -l {} \;

docker compose config --quiet
```

## 商品迁移

安装并启用 WooCommerce 与 Trovesia 插件后，可重复执行：

```bash
docker compose --profile tools run --rm wpcli trovesia migrate-catalog
```

命令会按来源 handle 与 SKU 更新商品，并避免重复下载已经迁移的图片。

公开评论及评论图片使用以下幂等命令迁移；图片会保存进本站媒体库：

```bash
docker compose --profile tools run --rm wpcli trovesia migrate-reviews
```

如果只需要同步评论文字，可附加 `--skip-media`。

## 通用关联加购规则

商品编辑页的 **Trovesia product add-ons** 配置框可以添加多个关联商品，且不
依赖固定的变体属性名称。每条规则支持跟随售价、固定金额、百分比优惠、数量、
可配置原价、默认选择以及适用的变体值。原价留空时读取关联商品原价，前台自动
计算并显示 `SAVE xx%` 与划线价格。

关联商品会作为独立购物车和订单行加入，使用自身的供货策略、税率、重量、
配送和退款逻辑。Batana 主商品只保留 Buy 1 / Buy 2 / Buy 3 三个基础变体；滚轮
作为通用关联商品处理。重新执行商品迁移不会覆盖后台已经保存的加购规则。

Compose 会将自定义代码挂载进容器，修改 PHP、CSS 或 JavaScript 后
无需重新构建镜像。

## FastCGI 页面缓存

Docker Nginx 会缓存匿名访客的公开 HTML 页面十分钟。购物车、结账、账户、
后台、API、查询参数、登录用户与 WooCommerce 会话全部绕过缓存。响应头
`X-Trovesia-Cache` 显示 `MISS`、`HIT`、`BYPASS` 或 `STALE`。

后台更新公开内容、商品、变体、库存、分类、菜单、主题或软件后，Trovesia
插件会更新共享的缓存代次文件。Nginx 监控进程验证配置并热重载，因此旧代次
立即停止命中，并在闲置一小时后由缓存管理器回收。

```bash
docker compose --profile tools run --rm wpcli trovesia page-cache status
docker compose --profile tools run --rm wpcli trovesia page-cache purge
```

## 一键部署

脚本默认部署到 `root@contabo2:/root/wordpress-sites/group-shop`，并检查
`https://www.animecats.online/`。

```bash
./scripts/deploy-theme.sh
./scripts/deploy-plugin.sh
./scripts/deploy-channel-plugin.sh
./scripts/deploy-all.sh
```

覆盖服务器、路径或检查 URL：

```bash
DEPLOY_HOST=root@example \
DEPLOY_PATH=/opt/wordpress-sites/group-shop \
DEPLOY_URL=https://shop.example.com/ \
./scripts/deploy-all.sh
```

部署前脚本会检查 PHP 语法，并在服务器的 `deploy-backups/` 备份旧版组件。
`rsync --delete-delay` 只作用于当前自定义主题或插件目录，不会删除上传文件、
数据库、`.env` 或其他 WordPress 组件。

## muti-storefront-plugin 0.2.0

分类/集合与无限库存由 `plugin/muti-storefront-plugin` 管理。当前网络商品与变体统一不管理库存、始终有货；不要沿用历史 Trovesia 模块的有限库存假设。分类模板是主店原生分类/标签，分店保留自己的原生 term 并建立来源映射，集合成员物化为原生分类关系。新增逻辑和发布规则以插件 ARCHITECTURE/HANDOFF 为准。
