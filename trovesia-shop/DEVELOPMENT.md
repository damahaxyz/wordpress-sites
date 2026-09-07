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
