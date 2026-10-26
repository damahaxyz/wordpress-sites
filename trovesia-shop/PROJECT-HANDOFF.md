# Trovesia Shop 项目交接与操作手册

> **用途**：这是 Trovesia 独立站的长期维护入口。新对话开始时先完整读取本文件，再查看当前工作区和线上状态，即可继续开发、部署、排障或上架商品。
>
> **最后核对日期**：2026-10-26（Asia/Taipei）

## 1. 新对话开始时先做什么

本地项目路径：

```text
/Users/dodo/Documents/wordpress-sites/trovesia-shop
```

新对话的建议开场指令：

```text
请先读取 /Users/dodo/Documents/wordpress-sites/trovesia-shop/PROJECT-HANDOFF.md，
再检查当前 Git 工作区和线上状态，然后继续处理我的 Trovesia 任务。
```

接手后按以下顺序工作：

1. 阅读本文件，不要凭旧对话记忆猜测当前配置。
2. 运行 `git status --short`，保留已有改动，不覆盖不属于本次任务的文件。
3. 涉及价格、库存、商品 ID、插件版本、GA、支付或缓存时，用 WP-CLI/后台重新查询线上状态。
4. 页面样式和交互改主题；持久业务逻辑、后台字段、购物车/订单规则改插件。
5. 修改后先本地检查，再使用项目部署脚本；部署后清缓存并在真实页面验证。
6. 如果本文件与代码或线上数据冲突，以当前代码和线上查询结果为准，并更新本文件的日期及相关章节。

重要安全规则：

- 不输出或提交 `.env`、数据库密码、SMTP 密码、PayPal 凭据、私钥或客户个人信息。
- 不把服务器证书私钥粘贴进聊天、日志或文档。
- 不直接修改 WordPress 核心或容器内的第三方插件文件；所有自定义逻辑放在本项目的主题或插件中。
- 不运行 `docker compose down -v`，除非明确要销毁数据库和上传文件。
- 商品和订单数据在数据库中；部署代码不会自动备份数据库，重要数据改动前先执行备份。

## 2. 项目概览

| 项目 | 当前值 |
| --- | --- |
| 正式站 | `https://www.trovesia.com` |
| 本地代码 | `/Users/dodo/Documents/wordpress-sites/trovesia-shop` |
| 服务器别名 | `root@site` |
| 服务器目录 | `/root/wordpress-sites/trovesia-shop` |
| 技术栈 | Docker Compose、WordPress 7.1.3、WooCommerce、PHP-FPM、Nginx、MariaDB、Redis |
| 商店币种 | USD |
| 固定链接 | `/%postname%/` |
| 自定义主题 | `trovesia` |
| 自定义插件 | `trovesia-plugin` |

Docker 服务：

- `db`：MariaDB 11.8
- `redis`：Redis 7.4
- `wordpress`：`wordpress:7.0-php8.3-fpm` 容器；当前容器内 WordPress core 为 7.1.3
- `nginx`：Nginx 1.28
- `wpcli`：按需运行的 WordPress CLI 工具容器

本机容器端口通常为：

- WordPress/Nginx HTTP：`127.0.0.1:8083`
- MariaDB：`127.0.0.1:3309`

正式域名由宿主机 Nginx 反向代理到 Docker Nginx。

## 3. 目录结构与职责

```text
trovesia-shop/
├── .env.example                 环境变量示例；真实 .env 不提交
├── compose.yaml                 WordPress、Nginx、MariaDB、Redis、WP-CLI
├── README.md                    基础启动与备份说明
├── DEVELOPMENT.md               简版开发说明
├── PROJECT-HANDOFF.md            本文件，未来对话的主要入口
├── nginx/
│   ├── conf.d/default.conf       Docker Nginx、FastCGI 与页面缓存规则
│   ├── cache-watch.sh            监听缓存代次并热重载 Nginx
│   └── host/                     宿主机站点配置参考
├── php/uploads.ini              PHP 上传限制
├── theme/trovesia/              展示层、模板、CSS、前端交互
├── plugin/trovesia-plugin/      业务逻辑、后台字段、购物车与迁移命令
├── scripts/                     部署、备份和一次性/幂等商品脚本
├── product-assets/              商品原图、处理图和可上传成品
└── runtime/page-cache/          FastCGI 缓存代次文件；运行时生成
```

### 3.1 主题 `theme/trovesia`

主题负责页面结构和视觉：

- `functions.php`：主题功能、菜单、资源加载、WooCommerce 集成。
- `front-page.php`：首页模板。
- `header.php` / `footer.php`：全站头尾。
- `page.php` / `single.php`：普通内容模板。
- `woocommerce.php`：WooCommerce 页面外层。
- `style.css`：全站视觉系统和通用组件。
- `assets/css/woocommerce.css`：商品、购物车、结账、账户、迷你购物车样式。
- `assets/js/theme.js`：菜单、商品选择、购物车和其他前端交互。

页面布局、颜色、字体、间距、响应式和非持久交互优先放在主题中。

### 3.2 自定义插件 `plugin/trovesia-plugin`

插件负责即使更换主题也必须保留的业务功能：

| 文件/模块 | 主要功能 |
| --- | --- |
| `trovesia-plugin.php` | 插件入口、版本与常量 |
| `includes/class-plugin.php` | 模块启动、`[trovesia_year]` |
| `includes/class-site-experience.php` | 站点页面、联系/物流查询、商品展示字段、变体卡片、结账保障等 |
| `includes/class-account-experience.php` | 登录/注册弹窗及 AJAX |
| `includes/class-addon-pricing.php` | 商品 add-on 规则、价格、购物车父子行、数量同步、订单元数据 |
| `includes/class-email-experience.php` | WooCommerce 品牌邮件样式与内容 |
| `includes/class-review-experience.php` | 评论列表、精选评论、图片评论、提交与后台列 |
| `includes/class-page-cache.php` | 页面缓存自动失效、后台工具、管理栏与 WP-CLI |
| `includes/class-catalog-migrator.php` | 幂等商品目录迁移 |
| `includes/class-review-migrator.php` | 幂等评论及评论图片迁移 |
| `data/catalog.php` | 原有三款商品的迁移数据源 |

主要插件功能：

- 安全的 `[trovesia_contact_form]` 和 `[trovesia_tracking_form]` 短代码。
- 自动创建 Contact 与 Track Order 页面。
- 后台私有保存客户咨询，并尝试发送通知邮件。
- 商品编辑页中的 eyebrow、benefits、guarantee、shipping、24 小时订单活动字段。
- 单属性可变商品的展开式变体卡片；当前 Batana bundle 与导液梳颜色均使用此逻辑。
- 多条关联加购规则：跟随价格、固定价、百分比优惠、对比原价、数量、默认选中、限定变体值。
- 加购商品以独立购物车和订单行存在，保留自己的库存、税、重量、运费、退款和报表逻辑。
- 主商品与 add-on 子项数量同步，防止一件主商品错误加入两份套装。
- WooCommerce 品牌邮件、账户体验、配送与保障提示。
- 真实的滚动 24 小时订单数；零订单时显示中性文案。
- 评论导入、评论图片本地化、精选评论、分页卡片和图片评论表单。
- 前台评论图片支持 JPG、PNG、WebP，最大 5 MB。重复商品评论默认继续拦截；购买过该商品的邮箱或拥有 `manage_woocommerce`/`manage_options` 权限的管理员可以重复评论。相关逻辑在 `class-review-experience.php` 的 `duplicate_comment_id` 过滤器中。
- Nginx FastCGI 页面缓存自动清理与管理工具。

插件专用商品元数据：

```text
_trovesia_eyebrow
_trovesia_benefits
_trovesia_guarantee
_trovesia_shipping
_trovesia_order_activity
_trovesia_addon_rules
_trovesia_source_handle
_trovesia_source_url
```

命名约定：

- 主题函数前缀：`trovesia_`
- 插件命名空间：`Trovesia\Plugin`
- 插件常量前缀：`TROVESIA_`
- 主题 text domain：`trovesia`
- 插件 text domain：`trovesia-plugin`

## 4. 已启用的主要插件（2026-10-26 快照）

| 插件 | 版本 | 用途 |
| --- | ---: | --- |
| `woocommerce` | 11.1.0 | 商品、购物车、订单、结账 |
| `woocommerce-paypal-payments` | 4.1.3 | PayPal 收款与快捷支付按钮 |
| `woocommerce-google-analytics-integration` | 2.4.2 | WooCommerce/GA4 统计 |
| `redis-cache` | 2.8.0 | WordPress 对象缓存 |
| `fluent-smtp` | 2.4.0 | 事务邮件投递 |
| `wp-media-folder` | 5.5.2 | 媒体库文件夹管理 |
| `trovesia-plugin` | 3.6.3 | 本站自定义业务功能 |

当前主题 `trovesia` 版本为 2.6.0。

当前线上 GA4 Measurement ID 为：

```text
G-M8186Y45Q9
```

之前曾提供过 `G-E53FPW111Q`，但 2026-10-26 线上 WooCommerce Google Analytics 设置实际读取到的是 `G-M8186Y45Q9`。任何调整前先在后台或 WP-CLI 再核对，不要同时加载两个 Measurement ID 造成重复统计。

## 5. 当前商品快照

以下仅是 2026-10-26 的核对结果。价格和库存属于动态数据，修改前应重新查询。

| ID | 商品 | 类型 | SKU | 发布状态 | 目录可见性 | 当前价格 |
| ---: | --- | --- | --- | --- | --- | ---: |
| 20 | Advanced Batana Hair Regrowth Therapy | variable | `WBX8FPF` | private | visible | 从 $35.95 起 |
| 25 | Authentic Derma Roller | simple | `1775139057712537603` | private | visible | $23.61，原价 $47.22 |
| 78 | Scalp Serum Applicator Comb | variable | `TV-COMB-1079994456070` | private | visible | $24.90，原价 $34.90 |
| 17 | Advanced Batana Hair Regrowth Therapy — 2 Bottle Bundle | simple | `TV-BATANA-2B-ADDON` | private | hidden | $71.95 基础价；add-on 规则可覆盖结算价 |
| 101 | Christmas Spark Atmosphere Wand Gift Set | variable | `TV-XMAS-SPARK-GIFT-SET` | publish | visible | $99.00 |

2026-10-26 16:29（Asia/Taipei），后台通过 WooCommerce 批量编辑把 ID 17、20、25、78 四个旧商品设为 `private`，站点的 `blog_public` 也为 `0`。匿名访客不能访问这四个旧商品，但新圣诞礼盒 ID 101 已公开发布并可购买。不要在未获用户确认时自行重新发布旧商品。

Batana 变体：

| 变体 ID | 变体值 | SKU | 售价 | 原价 |
| ---: | --- | --- | ---: | ---: |
| 21 | Buy 1, get 1 Free | `WBX8FPF-1` | $35.95 | $71.95 |
| 22 | Buy 2, get 2 Free | `WBX8FPF-2` | $71.95 | $143.90 |
| 23 | Buy 3, get 3 Free | `WBX8FPF-3` | $107.90 | $215.85 |

导液梳变体：

| 变体 ID | Color | SKU | 售价 | 原价 |
| ---: | --- | --- | ---: | ---: |
| 79 | Clear | `TV-COMB-CLEAR-6131064129457` | $24.90 | $34.90 |
| 80 | Pink | `TV-COMB-PINK-6131064129458` | $24.90 | $34.90 |

圣诞氛围棒礼盒变体：

| 变体 ID | Color | SKU | 售价 |
| ---: | --- | --- | ---: |
| 102 | Silver | `TV-XMAS-GIFT-SILVER` | $99.00 |
| 103 | Forest Green | `TV-XMAS-GIFT-GREEN` | $99.00 |
| 104 | Black | `TV-XMAS-GIFT-BLACK` | $99.00 |
| 105 | Pink | `TV-XMAS-GIFT-PINK` | $99.00 |
| 106 | Yellow | `TV-XMAS-GIFT-YELLOW` | $99.00 |

### 5.1 圣诞氛围棒礼盒的当前配置

- 正式页面：`https://www.trovesia.com/products/christmas-spark-atmosphere-wand-gift-set/`。
- 售卖单位是完整礼盒，不单卖氛围棒；礼盒含氛围棒、礼盒包装、一个材料盒和一小罐高压气罐。
- 商品没有功率参数，所有商品文案不得出现 `1000W`、瓦数或其他功率描述。
- 售价为 USD $99；五个颜色变体均有独立英文礼盒内容图，默认颜色为 Pink。
- 运输时间为 8–15 天。商品页的动态预计送达日期也按当前日期加 8 天至加 15 天计算。
- 高压气罐须保留运输限制和户外使用安全提示；具体承运资格可能因目的地而异。
- 导入脚本：`scripts/import-christmas-spark-wand.php`。
- 首页更新脚本：`scripts/update-christmas-storefront.php`。
- 原始和处理图片：`product-assets/focus-spark-atmosphere-wand/source/` 与 `product-assets/focus-spark-atmosphere-wand/ready/`。
- d1–d3 详情图已本地化为 `ready/d1-en.png`、`ready/d2-en.png`、`ready/d3-en.png`，对应线上附件 ID 115–117；图片相册现有 8 张 gallery 图片，连同主图共 9 张。
- 详情图本地化脚本：`scripts/localize-focus-detail-images.py`。它只替换说明文字，保留产品、技术图和真实包装印刷内容。
- 首页 page ID 57 的桌面/手机横幅与圣诞文案已更新，商品展示仅指向 ID 101；主菜单 `Shop Now`（menu item ID 92）也指向该商品。

### 5.2 导液梳的已知特殊配置

- 正式页面路径：`https://www.trovesia.com/products/scalp-serum-applicator-comb/`；商品处于 `private` 时匿名访问会返回 404。
- 来源商品编号：`1079994456070`。
- 本地图片：`product-assets/1079994456070/`。
- `source/` 保留供应商原图；`retouched/`、`ready/` 是处理过程和上传成品；不要覆盖原图。
- 商品包含梳子、滴管、注液口塞和防尘盖；文案必须明确 `Liquid not included.`。
- Clear/Pink 采用展开式变体卡片，变体描述来自 WooCommerce variation description。
- 导入脚本：`scripts/import-scalp-applicator-comb.php`。
- 图片处理脚本：`scripts/render-scalp-applicator-images.py`。
- add-on 修复脚本：`scripts/repair-comb-addon.php`。

导液梳的 Batana add-on 采用隐藏的简单商品 ID 17，原因是 add-on 规则需要一个明确、可购买的 **simple product**。原 Batana 商品 ID 20 是 variable product，代表多个 bundle，不能直接、无歧义地作为“一份两瓶套装”加入购物车。因此创建/保留 ID 17 来代表固定的 2 Bottle Bundle。用户已明确要求不要删除这个商品。

当前业务含义：

- 一份 add-on 商品代表一个两瓶套装。
- 主商品数量为 1 时，add-on 数量必须为 1，不能显示 2。
- add-on 固定促销价曾配置为 $29.90；该价格存在 `_trovesia_addon_rules` 中，使用前重新查询商品 78 的规则。
- `Quantity per main item` 应为 `1`。
- 如果限定颜色，`Allowed variation values` 必须精确匹配 `Clear`、`Pink`；留空表示所有变体。
- add-on 商品保持 `publish + hidden`，可购买但不在商店目录和搜索中单独展示。

### 5.3 迁移数据与线上数据的差异

`plugin/trovesia-plugin/data/catalog.php` 是原有三款商品的迁移数据源，但它不一定与后台后续手工调整完全一致。当前线上 Batana 变体标签是 `Buy 1, get 1 Free` 等，而数据文件可能仍使用较短标签。

因此不要在未比较差异时直接运行：

```bash
docker compose --profile tools run --rm wpcli trovesia migrate-catalog
```

运行迁移前应先：

1. 备份数据库。
2. 对照 `data/catalog.php` 与线上商品名称、价格、变体、图片和描述。
3. 如只更新文字或数据而不下载图片，使用 `--skip-images`。
4. 迁移脚本会保留已经存在的 `_trovesia_addon_rules`，但仍需在购物车实际验证。

## 6. 如何上架新商品

### 6.1 先收集完整信息

至少确认：

- 供应商来源 URL 和来源商品 ID。
- 商品英文名称、slug、SKU、分类。
- 所有变体及供应商 SKU。
- 售价、原价、成本、重量、尺寸、库存方式。
- 包装内含物、使用方式、材质和合规说明。
- 主图、变体图、细节图、尺寸图、包装图。
- 是否发布、草稿或隐藏；是否允许 add-on。

不要根据图片猜测医疗功效、认证、容量、材质或配件。头发护理商品尤其避免未经证实的 `regrowth`、治疗、治愈等医学承诺；已经存在的商品名如需调整，应同时评估广告和支付平台合规。

### 6.2 下载和处理图片

按来源 ID 建立目录：

```text
product-assets/<source-id>/
├── source/       完整保留供应商原图
├── retouched/    去中文、翻译、修图和中间文件
└── ready/        最终上传文件
```

图片处理要求：

- 先保存原始文件，不在 `source/` 上直接覆盖。
- 主图优先使用干净背景和真实产品结构，不能漏掉配件或改变产品外观。
- 中文说明图应翻译为自然英文，并保留箭头、部件位置和事实关系。
- 不使用供应商水印、联系方式、二维码或平台促销信息。
- 保持各颜色/规格一致的角度和比例。
- 最终文件使用清晰、稳定的英文文件名和 WebP/JPEG；建议商品方图约 1200×1200，按实际清晰度调整。
- 上传后填写描述性 alt text，不堆砌关键词。

### 6.3 选择创建方式

少量一次性商品可在 WooCommerce 后台创建。需要重复部署、含多张本地图片或复杂变体的商品，优先编写幂等 WP-CLI PHP 脚本，参考：

```text
scripts/import-scalp-applicator-comb.php
```

脚本应做到：

- 按唯一 SKU 或来源 meta 查找现有商品，而不是每次新建。
- 媒体按来源 URL、文件名或自定义 meta 复用，避免重复附件。
- 先创建/更新父商品，再创建属性与变体。
- 每个变体设置唯一 SKU、价格、图片、描述、库存和重量。
- 设置默认变体，并同步 variable product。
- 重复运行结果一致。
- 默认先保存为 draft；价格、图片和变体确认后再 publish。

服务器执行一次性脚本的常见方式：

```bash
cd /root/wordpress-sites/trovesia-shop
docker compose --profile tools run --rm wpcli eval-file scripts/example-import.php
```

若脚本和图片只在本地，先将脚本及所需 `product-assets/` 同步到服务器；不要把供应商临时 Cookie 或账号凭据写入脚本。

### 6.4 WooCommerce 后台字段

简单商品：

1. 填写商品名、长描述、短描述。
2. 设置 regular price / sale price。
3. 设置唯一 SKU、库存状态、重量和尺寸。
4. 选择分类、主图和 gallery。
5. 填写 Trovesia product presentation 字段。
6. 检查 catalog visibility 后发布。

可变商品：

1. 商品类型选 Variable product。
2. 建立用于 variations 的属性，例如 `Color` 或 `Bundle`。
3. 创建每个 variation。
4. 每个 variation 单独设置 SKU、价格、图片、库存、重量和 Description。
5. 设置默认 variation。
6. 前台展开式卡片会显示 variation description；不要把需要展示的差异只写在父商品描述里。

Trovesia 展示字段：

- Eyebrow：商品标题上方短标签。
- Benefits：每行一条卖点。
- Guarantee：退换/保障短文案。
- Shipping：配送时效短文案。
- Order activity：可留空使用真实 24 小时订单活动，不伪造销量。

### 6.5 配置 add-on

在主商品编辑页找到 **Trovesia product add-ons**：

1. Linked product 必须是已发布、可购买的 simple product。
2. Display title 是前台显示名，可留空使用商品名。
3. Pricing mode：跟随商品价、固定价格或百分比优惠。
4. Fixed price 是实际 add-on 单价。
5. Original / compare-at price 只用于真实的对比价及折扣标签。
6. Quantity per main item 通常设为 1。
7. Allowed variation values 以英文逗号分隔，且必须与父商品变体值完全一致；留空适用于所有变体。
8. Selected by default 只有业务上确实默认加入时才勾选。

保存后必须验证：

- 页面只出现一组 `Complete your order`。
- 切换每个主商品变体时，add-on 正确显示或隐藏。
- 选择 add-on 后加入购物车，主商品和 add-on 各一条购物车行。
- 主商品数量 1 时 add-on 数量为 1；主商品数量变化时子项同步。
- 删除主商品时关联子项一并移除。
- 购物车、迷你购物车、结账和订单后台的名称、数量、单价、小计正确。

### 6.6 发布后的商品验收

- 桌面和手机都检查首屏、图片比例、变体卡片、价格、折扣和按钮。
- 每个变体均可选择，图片、description、价格与 SKU 匹配。
- 加入购物车、修改数量、删除、迷你购物车和结账均正常。
- PayPal 按钮与普通 Checkout 按钮都可见且无重叠。
- 运费、税、货币、库存和订单邮件正确。
- 页面源码不含中文供应商文案、临时路径或失效图片。
- 清理页面缓存后以匿名窗口再验证一次。

## 7. 页面缓存与 Redis

### 7.1 两类缓存的区别

- Redis Object Cache：缓存 WordPress 对象和数据库查询结果。
- Nginx FastCGI Cache：缓存匿名访客的完整 HTML 响应，直接减少 PHP/WordPress 执行，主要用于降低 TTFB。

当前 FastCGI Cache 规则：

- 只缓存匿名用户的公开 GET/HEAD HTML。
- 默认有效约 10 分钟，最大缓存约 512 MB。
- 登录用户、WooCommerce session、购物车、结账、账户、后台、API、带查询参数和授权请求绕过缓存。
- 因此登录用户的首页和商品页通常也绕过页面缓存，这是为了避免账户、购物车和个性化内容串号。
- 响应头 `X-Trovesia-Cache` 显示 `MISS`、`HIT`、`BYPASS` 或 `STALE`。

插件在以下变化后更新缓存代次：内容、商品、变体、库存、分类、菜单、主题、插件和部分公开设置。Nginx watcher 验证配置并热重载，使旧代次立即不再命中。

管理命令：

```bash
docker compose --profile tools run --rm wpcli trovesia page-cache status
docker compose --profile tools run --rm wpcli trovesia page-cache purge
```

后台入口：**Tools > Trovesia Page Cache**，管理员工具栏也有清理入口。

验证缓存：

```bash
curl -I https://www.trovesia.com/
curl -I https://www.trovesia.com/
curl -I 'https://www.trovesia.com/?cache-bypass-check=1'
```

正常情况下，首次可能为 `MISS`，第二次为 `HIT`，带查询参数为 `BYPASS`。如果更新内容后仍看到旧页面，先运行 purge，再检查 `runtime/page-cache/generation.conf` 和 Nginx watcher 日志。

## 8. 本地检查、部署与备份

### 8.1 修改前

```bash
cd /Users/dodo/Documents/wordpress-sites/trovesia-shop
git status --short
git diff -- theme/trovesia plugin/trovesia-plugin
```

当前工作区可能有未提交的 Trovesia 改动，尤其是：

```text
plugin/trovesia-plugin/includes/class-addon-pricing.php
plugin/trovesia-plugin/includes/class-site-experience.php
theme/trovesia/assets/css/woocommerce.css
theme/trovesia/assets/js/theme.js
theme/trovesia/style.css
product-assets/
scripts/import-scalp-applicator-comb.php
scripts/render-scalp-applicator-images.py
scripts/repair-comb-addon.php
```

接手时重新运行 `git status`，这份列表只是提醒，不能作为覆盖现状的依据。仓库上层还可能有另一个 `tikool-shop` 工作区改动；不要把两个站点的文件混在同一次提交或部署中。

### 8.2 语法与配置检查

```bash
find theme/trovesia plugin/trovesia-plugin \
  -type f -name '*.php' -print0 | xargs -0 -n1 php -l

node --check theme/trovesia/assets/js/theme.js
docker compose config --quiet
```

只针对本次修改运行必要检查；如果修改购物车/add-on，应补做真实浏览器和 WooCommerce 购物车验证，而不是只看 PHP 语法。

### 8.3 部署

```bash
./scripts/deploy-theme.sh
./scripts/deploy-plugin.sh
./scripts/deploy-all.sh
```

默认目标：

```text
root@site:/root/wordpress-sites/trovesia-shop
```

脚本会：

- 检查 PHP 语法。
- 在服务器 `deploy-backups/` 备份旧组件。
- 使用 rsync 更新指定主题/插件目录。
- 检查 Docker Compose 配置并重启相关容器。
- 执行健康检查和正式 URL 检查。

`rsync --delete-delay` 只作用于当前部署的自定义主题或插件目录，不会删除数据库、上传文件、`.env` 或其他插件。

若需要覆盖目标：

```bash
DEPLOY_HOST=root@example \
DEPLOY_PATH=/opt/wordpress-sites/trovesia-shop \
DEPLOY_URL=https://shop.example.com/ \
./scripts/deploy-all.sh
```

部署后：

1. 清理 FastCGI 页面缓存。
2. 打开正式页面确认资源版本已更新。
3. 检查浏览器控制台和网络请求。
4. 对购物车/结账改动执行完整购买路径测试。

### 8.4 备份

```bash
./scripts/backup.sh
```

备份包含数据库、`wp-content` 和校验文件。涉及批量商品迁移、评论导入、价格更新或插件升级前先备份。

## 9. 常用 WP-CLI 命令

服务器上：

```bash
ssh root@site
cd /root/wordpress-sites/trovesia-shop
docker compose --profile tools run --rm wpcli plugin list
docker compose --profile tools run --rm wpcli theme list
docker compose --profile tools run --rm wpcli post list --post_type=product --fields=ID,post_title,post_status
```

商品目录迁移：

```bash
docker compose --profile tools run --rm wpcli trovesia migrate-catalog
docker compose --profile tools run --rm wpcli trovesia migrate-catalog --skip-images
```

评论迁移：

```bash
docker compose --profile tools run --rm wpcli trovesia migrate-reviews
docker compose --profile tools run --rm wpcli trovesia migrate-reviews --skip-media
```

页面缓存：

```bash
docker compose --profile tools run --rm wpcli trovesia page-cache status
docker compose --profile tools run --rm wpcli trovesia page-cache purge
```

临时查询优先使用只读 `wp eval`，不要把客户邮箱、电话、地址或订单明细打印到聊天记录。

## 10. 常见问题定位

### 变体仍显示下拉框

- 展开式卡片只适用于恰好一个 variation attribute 的 variable product。
- 检查每个 variation 是否启用、可购买、有价格并有属性值。
- 检查 `class-site-experience.php` 输出和 `theme.js` 初始化是否成功。
- 只有 JS 初始化成功后才隐藏 WooCommerce 原生下拉框，避免脚本失败时商品无法购买。
- 清缓存并检查浏览器控制台。

### 变体卡片副标题不对

- 卡片正文读取 variation Description。
- 到每个 variation 中填写 Description，而不是父商品短描述。
- 保存后清 FastCGI cache。

### add-on 不显示

- Linked product 必须是 publish、purchasable 的 simple product。
- 检查主商品 `_trovesia_addon_rules` 是否保存。
- 检查 `Allowed variation values` 是否精确匹配当前变体标签；大小写和空格都可能影响匹配。
- 检查关联商品价格和库存状态。
- 若主商品是 variable product，原生父级商品不能代替明确的 simple add-on bundle。

### 出现两个 `Complete your order`

- 检查主题和插件是否都输出了标题。
- 当前标题应由 add-on 组件统一输出一次；不要在商品模板另写同名标题。
- 清缓存后再判断，避免旧 HTML 与新 CSS/JS 混合。

### add-on 数量变成 2

- `Quantity per main item` 应为 1。
- 一份“2 Bottle Bundle”是一个商品单位，瓶数写在商品含义中，不等于购物车数量 2。
- 检查父子购物车行的 `_trovesia_addon_parent_key` 和规则快照。
- 服务器端必须重新同步数量，不能只依赖前端显示。

### 迷你购物车标题掉到图片下方

- 检查 `assets/css/woocommerce.css` 中购物车行的 grid/flex 布局。
- 图片、标题、移除按钮和数量/价格应有明确列；同时验证窄屏换行。

### PayPal 按钮正常但普通 Checkout 按钮颜色/状态异常

- PayPal 按钮由官方插件 iframe/SDK 渲染，普通 Checkout 按钮由主题样式控制。
- 检查按钮是否真实 disabled，而不是只因低对比度看起来不可用。
- 不要用过宽的通用 `button` CSS 覆盖 PayPal 容器。

### TTFB 约 2 秒

- 对匿名页面的缓存 `HIT` 来说偏慢，应继续排查 CDN/网络、宿主代理和 Nginx。
- 对 `MISS/BYPASS` 的 WooCommerce 动态请求，2 秒不罕见，但仍可从慢查询、外部 API、插件、PHP workers、数据库和 Redis 命中率优化。
- 先看 `X-Trovesia-Cache` 再判断，不能把 HIT 和 BYPASS 混为一谈。

## 11. 当前需要持续注意的事项

- 线上 GA4 ID 与早期提供的 ID 不同；任何统计问题先确认只加载一次当前 ID。
- PayPal 插件已启用，但支付账户、webhook、币种和沙盒/正式模式属于外部动态配置，不能仅凭代码推断。
- `data/catalog.php` 与线上 Batana 变体文案存在差异，运行迁移前必须比较。
- 商品价格、库存和插件版本会变化，本文快照不是永久真值。
- 导液梳 add-on 的隐藏简单商品 ID 17 有明确业务用途，不要删除。
- 图片必须忠实呈现滴管、注液口塞、防尘盖等配件，不要用生成图遗漏结构。
- 当前本地有未提交的主题、插件、商品脚本和图片改动；新对话先检查并保护这些改动。
- 2026-10-26 16:29（Asia/Taipei）后台明确将商品 17、20、25、78 批量设为 `private`，且 `blog_public=0`；当前商店不是公开可购买状态，重新发布前必须先得到用户确认。

## 12. 完成任务后如何维护本文件

发生以下变化时更新本文件：

- 项目目录、服务器、部署方式或 Docker 服务变化。
- 自定义主题/插件新增重要模块。
- 商品导入方式、add-on 规则或缓存机制变化。
- 新增需要后续维护的商品脚本或资产目录。
- 线上重要商品 ID、SKU 或业务含义变化。
- 发现新的高频故障及解决办法。

更新时：

1. 修改顶部“最后核对日期”。
2. 把动态数据明确标记为快照。
3. 删除已经失效的说明，避免同时保留相互矛盾的旧方案。
4. 不写任何凭据、客户信息或私钥。

