# Trovesia Shop 自定义开发

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

plugin/trovesia-plugin/
├── trovesia-plugin.php        插件入口与常量
├── includes/class-plugin.php
├── includes/class-site-experience.php
├── includes/class-catalog-migrator.php
├── includes/class-review-migrator.php
├── data/catalog.php
└── uninstall.php           卸载时清理插件自己的设置
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
find theme/trovesia plugin/trovesia-plugin \
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

关联商品会作为独立购物车和订单行加入，因此继续使用自身的库存、税率、重量、
配送和退款逻辑。Batana 主商品只保留 Buy 1 / Buy 2 / Buy 3 三个基础变体；滚轮
作为通用关联商品处理。重新执行商品迁移不会覆盖后台已经保存的加购规则。

Compose 会将自定义代码挂载进容器，修改 PHP、CSS 或 JavaScript 后
无需重新构建镜像。

## 一键部署

脚本默认部署到 `root@site:/root/wordpress-sites/trovesia-shop`，并检查
`https://www.trovesia.com/`。

```bash
./scripts/deploy-theme.sh
./scripts/deploy-plugin.sh
./scripts/deploy-all.sh
```

覆盖服务器、路径或检查 URL：

```bash
DEPLOY_HOST=root@example \
DEPLOY_PATH=/opt/wordpress-sites/trovesia-shop \
DEPLOY_URL=https://shop.example.com/ \
./scripts/deploy-all.sh
```

部署前脚本会检查 PHP 语法，并在服务器的 `deploy-backups/` 备份旧版组件。
`rsync --delete-delay` 只作用于当前自定义主题或插件目录，不会删除上传文件、
数据库、`.env` 或其他 WordPress 组件。
