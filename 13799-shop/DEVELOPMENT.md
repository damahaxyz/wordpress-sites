# AROMAMATRIX 主题与插件开发

仓库中的自研代码分成两个独立组件：

```text
theme/aromamatrix/                 自研主题，只负责页面结构与视觉
plugin/aromamatrix-plugin/         自研插件，负责站点业务功能
```

不要修改 WordPress、WooCommerce 或其他插件的核心文件。不要把询价流程、
产品字段、第三方 API 等业务逻辑放进主题，否则切换主题时功能也会消失。

## 本地启用

Compose 会把两个目录分别挂载进 WordPress：

```text
theme/aromamatrix
  → /var/www/html/wp-content/themes/aromamatrix

plugin/aromamatrix-plugin
  → /var/www/html/wp-content/plugins/aromamatrix-plugin
```

Nginx 也会以只读方式挂载它们，以便直接提供 CSS、JavaScript 与图片。

首次应用 Compose 变更：

```bash
docker compose config --quiet
docker compose up -d --force-recreate wordpress nginx
docker compose ps
```

进入 WordPress 后台，仅需启用一次：

```text
外观 → 主题 → AROMAMATRIX → 启用
插件 → 已安装的插件 → AROMAMATRIX Plugin → 启用
```

后续修改挂载目录中的文件会立即反映到容器中，不需要重新复制到
`wp-content`。

## 组件职责

### 主题

- PHP 模板与页面布局
- CSS、JavaScript、图片等前端资源
- `theme.json` 中的编辑器设计规范
- WooCommerce 页面展示与 hooks

### 插件

- 询价和 WhatsApp 工作流
- 自定义产品字段
- REST API 与第三方服务集成
- 排程、后台设置和其他站点业务逻辑

插件的初始版本不修改前台行为，只提供可继续扩展的入口。

## 一键部署

默认服务器为 `root@site`，项目目录为 `/root/wordpress-sites/13799-shop`。

只部署主题：

```bash
./scripts/deploy-theme.sh
```

只部署插件：

```bash
./scripts/deploy-plugin.sh
```

同时部署主题和插件：

```bash
./scripts/deploy-all.sh
```

可以临时覆盖目标：

```bash
DEPLOY_HOST=root@example \
DEPLOY_PATH=/opt/wordpress-sites/13799-shop \
DEPLOY_URL=https://example.com/ \
./scripts/deploy-all.sh
```

如果测试环境没有可访问的网址，可以跳过 HTTP 检查：

```bash
DEPLOY_URL='' ./scripts/deploy-all.sh
```

部署脚本会：

1. 检查必需文件和本机 PHP 语法。
2. 将服务器上的旧组件备份到 `deploy-backups/`。
3. 只同步指定主题或插件目录。
4. 校验 Compose、刷新 WordPress 并等待健康检查。
5. 检查网站 URL。

`rsync --delete-delay` 只作用于对应的自研组件目录，不会操作 `.env`、
上传文件、数据库、其他插件或其他主题。

## 第一次发布到现有服务器

生产服务器必须先收到修改后的 `compose.yaml`，再执行组件部署。可以按照
`DEPLOYMENT.md` 的 Compose 更新流程上传和校验：

```bash
scp compose.yaml root@site:/root/wordpress-sites/13799-shop/compose.yaml.new

ssh root@site
cd /root/wordpress-sites/13799-shop
docker compose -f compose.yaml.new config --quiet
mv compose.yaml.new compose.yaml
mkdir -p theme/aromamatrix plugin/aromamatrix-plugin
```

回到本机执行：

```bash
./scripts/deploy-all.sh
```

最后在 WordPress 后台启用主题和插件。部署代码不会擅自修改生产数据库中的
启用状态。

## 发布前检查

```bash
find theme/aromamatrix plugin/aromamatrix-plugin \
  -type f -name '*.php' -exec php -l {} \;

docker compose config --quiet
git status
```

如果主题以后加入 Node.js 构建流程，应在部署脚本同步文件之前运行
`npm ci` 和 `npm run build`，并继续排除 `node_modules/`。
