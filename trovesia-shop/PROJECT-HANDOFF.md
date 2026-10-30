# Trovesia Shop 项目交接与操作手册

> **用途**：这是 Trovesia 独立站的长期维护入口。新对话开始时先完整读取本文件，再查看当前工作区和线上状态，即可继续开发、部署、排障或上架商品。
>
> **最后核对日期**：2026-10-30（Asia/Taipei）

## 2026-10-29 安全事件与恢复状态

网站因恶意 PHP 插件启动伪装 Perl 进程而发生动态请求阻塞。两个 Maintenance Tools 随机目录插件 `wp-3hppkwvobt`、`wp-4jtash8wf0` 已隔离，PHP 容器已从原镜像重建，公网首页/商品页及结账页面恢复。详情见 `docs/incidents/2026-10-29-wordpress-compromise.md`。

- 站主确认 `wp-media-folder` 5.5.2 是本人主动安装，要求恢复正常使用；已纳入白名单，并按该指示恢复。静态扫描未发现已确认的后门，不能据此证明整个旧发行包完全安全；后续使用站主正版新包更新。Maintenance Tools 不是站主安装，两个恶意目录继续隔离。
- Nginx 已阻止直接公开执行 `/wp-content/` 下 PHP/PHTML/PHAR；PHP 禁止启动 shell/外部进程。配置分别位于 `nginx/conf.d/default.conf`、`php/uploads.ini`。
- 旧登录会话已全部失效，需重新登录。后台密码及支付/SMTP 凭据仍需站主更换/轮换。
- 后续历史日志分析已证实：同一来源在 10-25 05:44 与 10-28 03:08（Asia/Taipei）登录后台，经 `update.php?action=upload-plugin` 上传恶意插件，再直接执行插件 PHP。凭据最初如何泄露及是否发生数据外传仍未知；不能将媒体插件认定为此次已证实入口。完整时间线见上述事件报告。
- root 私有取证/隔离与数据库备份目录为服务器 `/root/trovesia-incident-20261029/`；可能包含恶意样本和凭据，严禁提交或公开，不可作为干净备份整体回滚。
- 已部署 MU 安全模块、官方 Two Factor 0.17.0、全密码入口限速、管理员原生登录与 Cloudflare 访问控制、后台代码修改锁、root 代码权限/只读挂载、代码 SHA-256 清单和插件白名单。2026-10-29 站主明确要求两步验证可关闭：已改为个人可选，不再强制勾选 Email，`damaha` 当前关闭两步验证。管理员仍从 `/wp-login.php` 登录。
- 2026-10-30 按站主明确要求，管理员 IP 限制移交 Cloudflare，MU 安全模块 1.2.0 已删除网站层名单及登录/后台/AJAX/REST 来源拦截，`admin-access.json` 已私有归档移除。以后更换 IP 只改 Cloudflare；宿主 Nginx 仅允许 Cloudflare 回源。公开 AJAX/REST 仍依赖登录、权限和 nonce，不再额外按管理员 IP 拦截。
- 已补查 10 个启用插件及主题的 wp-login 依赖。渠道与顾客退出均须保留 GET logout；当前交易/账户主流程走前台接口。只放行退出会阻止核心密码保护内容、隐私确认和其他原生账号恢复；当前密码保护内容/隐私请求为 0。边界、版本与证据见 `docs/WP-LOGIN-ACCESS-REVIEW.md`；不要把主流程可用解释为所有核心/未来插件流程都不受影响。
- 数据库应用密码和 WordPress 认证 salts 已轮换；站主密码、邮箱和支付/SMTP 平台凭据仍需站主处理。
- 部署脚本现强制执行部署前后安全检查；安全配置使用 `scripts/deploy-security.sh`，组件使用原组件脚本。不要绕过失败检查或直接重建可信清单。完整操作见 `docs/SECURITY-OPERATIONS.md`。
- 服务器 `trovesia-security-watch.service` 每五分钟做轻量容器/动态页/认证审计检查，完整权限/哈希检查改为约每小时一次，失败则下一轮重试；部署前后仍完整检查。异常及恢复提醒继续发至现有 WordPress 管理员邮箱。备份按站主选择仅放 `/root/trovesia-backups/`，用 `scripts/security-backup.sh`；未设置异地或定时备份。
- FluentSMTP Gmail 配置依赖登录 salts 加密；本次轮换后已在 root 私有环境重新加密原配置并成功调用发信。未来换 salts 必须同时处理 SMTP 加密兼容，门禁已增加解密哨兵检查；具体步骤见安全手册。实际收件仍需站主确认。

## 2026-10-30 安全精简

`security/` 运行目录从本地 13 文件精简为 8 文件（线上另归档一份遗留邮件诊断）；手工测试迁至 `tests/security/`，SMTP 一次性修复迁至 `scripts/maintenance/`。不再在安全部署时无条件注销所有管理员。保留代码只读/权限、后台代码修改锁、登录限速、危险 PHP 路径阻断、完整性门禁、日志轮转和状态变化告警。完整文件分析见 `docs/SECURITY-SIMPLIFICATION.md`；旧事件中的网站层 IP 保护描述属于历史状态。

Cloudflare 已发布第二条 Block 规则，范围为 `/wp-admin` 或 `/wp-login.php` 开头，保留原三个白名单 IP；第一条 Skip 保留公开 admin-post/admin-ajax 以及 GET、单个精确 `action=logout` 的标准退出参数。此前线上 Block 遗漏登录路径，现已补齐。以后改 IP 只编辑 CF 的 `admin IP 加白`，勿恢复服务器旧名单。完整表达式见精简说明。

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
   安全门禁必须通过：`security-baseline.sh check` → 授权部署 → `apply` / `seal` / `check`；详细流程见 `docs/SECURITY-OPERATIONS.md`。第三方代码更新走服务器 root 的 `maintenance-wp.sh`，普通商品/媒体导入继续 UID 33。
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
├── plugin/trovesia-channel/     渠道管理、推广链接、访问与订单归因、渠道门户
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

### 3.3 渠道插件 `plugin/trovesia-channel`

2026-10-26 已开发并在线上启用独立的 `trovesia-channel` 插件，当前版本 1.7.3。它包括：

- 总后台渠道、渠道账户和全局归因窗口管理；归因窗口不向渠道账户显示。
- 渠道账户的独立前台门户 `/channel-portal/`。
- 渠道方可为首页、Shop、公开商品分类和公开可见商品生成 `https://trovesia.com/s/{token}/` 推广链接，token 为 6 位英文字母/数字。
- 不透明令牌跳转、第一方访问归因、机器人标记和干净目标 URL 重定向。
- Checkout Block、经典结账和 HPOS 订单元数据归因。
- 订单状态、付款金额和退款金额同步，以及渠道隔离的订单/统计查询。
- WooCommerce 订单列表渠道列、渠道筛选和带审计记录的人工归属。

相关部署接入：

- `compose.yaml` 已增加 `trovesia-channel` 的 WordPress、WP-CLI 和 Nginx 挂载。
- `nginx/conf.d/default.conf` 明确让 `/s/{token}`（及历史 `/go/` 路径）绕过 FastCGI 页面缓存。
- 单独部署脚本为 `scripts/deploy-channel-plugin.sh`；`deploy-all.sh` 也会包含该插件。

2026-10-26 23:29（Asia/Taipei）完成正式部署与验证：六张插件数据表、`trovesia_channel_manager` 角色和 Channel Portal page ID 121 已创建，归因窗口默认为 30 天。真实 `/go/{token}` 请求已验证 302 商品跳转、安全 Cookie 和 FastCGI `BYPASS`；临时 HPOS 订单已验证渠道/Campaign/Visit 元数据、processing 状态、$10 部分退款与 $89 净额同步。测试订单、退款、渠道、链接、访问记录和测试账户随后全部删除，未残留测试数据。

2026-10-26 发现渠道账户创建表单容易把“用户名”误认为“密码”。1.0.1 将登录邮箱、用户名和初始密码拆分为明确字段：新账户可由总站管理员设置初始密码；留空则生成安全随机密码并发送设置密码邮件；绑定已有邮箱不会改动已有用户密码。

1.0.2 将密码字段扩展为总站管理员可直接设置或重置渠道账户密码：新账户留空时仍发送安全的密码设置邮件；已有账户留空保持密码不变，填写则立即重置并写入不含密码内容的审计记录。

1.0.3 在编辑渠道时自动回填当前代理的登录邮箱和 WordPress 用户名。密码不会回填，因为 WordPress 只保存不可逆哈希；密码框仅用于新建或主动重置。

1.1.0 将总站后台的“创建渠道”和“编辑渠道”改为同一个弹窗表单。弹窗可一次保存渠道名称、代码、状态和可选代理账户；编辑时自动带出邮箱及用户名，密码仍不回填，留空保持原密码。

1.1.1 修复从带 `channel_id` 的后台页面打开“新增渠道”时浏览器可能恢复旧渠道默认值的问题；新增弹窗现在始终清空渠道及代理字段。

1.2.0 重新设计渠道登录页与渠道数据门户：门户页不再套用普通内容页的大标题/白色外框，登录表单改为紧凑品牌卡片；登录后加入品牌概览区、分段时间筛选、数据卡片、规范化表单、状态徽章、表格容器与完整移动端响应布局。

1.2.1 移除门户模板重复输出的页面级标题，确保登录页和渠道数据页各自只有一个明确的主标题。

1.2.2 将渠道登录从 WordPress 原生 `wp-login.php` 提交改为插件自己的安全登录处理。账号或密码错误、登录 nonce 过期时都会返回 `/channel-portal/` 并在品牌登录卡片内显示统一错误，不再跳转到 WordPress 系统登录页。

1.3.0 按站主要求取消新渠道账号的设置密码邮件。后台新建账号必须填写至少 6 位初始密码，由管理员交给渠道；编辑已有账号时留空保持原密码。登录后的门户在 Sign out 旁增加 Reset password 弹窗，输入旧密码、新密码和确认新密码；站主确认前台新密码也至少 6 位。通过公开 `admin-ajax.php` 提交，校验当前登录身份、渠道权限和 nonce，只能修改自己，错误旧密码每 15 分钟最多 5 次；成功后重新签发当前浏览器 Cookie、撤销其他会话，审计不含密码。渠道新建/前台改密不再依赖原生设置密码链接；退出仍使用 `wp-login.php?action=logout`，顾客找回密码仍走 WooCommerce。真实 HTTP 回归脚本为 `tests/channel-password-self-test.php`，仅使用可清理的测试账户。

2026-10-29（Asia/Taipei）已部署 1.3.0，部署前后安全门禁通过。32 项集成检查通过，覆盖创建无邮件/无重置 key、6 位边界、CSRF/旧密码/确认密码/越权/限速、旧 Cookie 失效及新 Cookie 继续登录；临时测试账户和渠道已清理。Chrome 实际页面的弹窗打开、取消、Escape 关闭及 390px 窄屏布局均已核对，没有改动现有用户密码。截图和脱敏验收记录位于 `qa/channel-password-20261029/`；部署前数据库及文件备份为服务器 `/root/trovesia-backups/20261029T113652Z`。

1.4.0 将渠道门户拆分为 `Overview & orders` 与 `Tracking links` 两个标签页；创建推广链接和链接列表只在链接标签显示。订单查询改为数据库级分页，每页固定 20 条，并在翻页时保留统计时间范围与当前标签。

2026-10-30（Asia/Taipei）已部署 1.4.0，部署前后安全门禁、权限与动态页面健康检查通过。真实渠道会话已验证两个标签互斥显示及移动端布局；事务内加入 25 条临时转换记录后验证总计 27 条时第 1 页返回 20 条、第 2 页返回 7 条、越界页归到最后一页，分页链接保留 `tab=overview` 和时间范围。事务回滚后渠道转换记录恢复为原 2 条，没有留下测试订单或转换数据。

1.5.0 按站主要求将分享地址缩短为 `https://trovesia.com/s/{6位字母数字}/`，总站和渠道门户统一使用该格式；随机 token 先查重，并受数据库唯一索引保护。升级时自动刷新 WordPress 路由。根域名继续 301 到 `www` 的相同路径后再由插件记录访问及写 Cookie，确保进入现有商城域名后归因可用。站主明确要求清空现有推广链接、不保留旧链接；清理前已完成服务器私有备份 `/root/trovesia-backups/20261029T170949Z`，订单和访问统计保留。

2026-10-30 已完成 1.5.0 和 `/s/` 缓存绕过规则部署，部署前后安全门禁通过。已备份并清空旧推广链接 ID 2–6（期间新增一条，最终共 5 条），额外精确备份为上述目录的 `channel-links-before-clear.json`；清理前后访问记录均为 10 条、归因订单均为 2 条，清理后推广链接为 0 条。`tests/channel-short-links-self-test.php` 的 14 项线上检查通过，覆盖根域名跳转、6 位路由、缓存 BYPASS、Cookie、未保存订单的归因、暂停/移除链接的回退；临时渠道、链接和访问记录均已清理，未创建测试订单。真实渠道门户已确认空列表。日常“删除链接”按钮尚未增加；已向站主建议保留历史统计并在二次确认后移除链接。

1.5.1 修复门户营收卡片金额样式：卡片标签样式仅作用于直属标签，WooCommerce 金额不再被误设为小字号/块级元素；金额继承大号主数字样式，货币符号缩小且保持同行，使用等宽数字。2026-10-30 已部署，前后安全门禁通过；真实渠道页确认两张营收卡片由 11px 灰色块级金额恢复为当前视口 29px 深色行内金额，符号同行且金额禁止拆行。

1.6.0 加入渠道门户删除链接功能，按站主要求必须先暂停：仅 Paused 行显示 Delete，弹窗显示名称和统计保留说明，默认聚焦 Cancel。删除只接受登录渠道账号的 POST，校验逐链接 nonce，并原子匹配渠道归属和 paused 状态。采用 `status=deleted` 保留链接行、token 和历史访问/订单统计，门户列表不再显示；旧启用 URL 也不能恢复已删除链接。2026-10-30 已部署，部署前后安全门禁通过；`tests/channel-delete-link-self-test.php` 20 项检查通过，覆盖 Active/跨渠道/无效 nonce/GET/匿名拦截、正确删除、历史统计、失效跳转、列表隐藏、阻止重新启用及重复提交。临时账号、渠道、链接、访问、转换和审计记录已清理，未创建真实订单。真实渠道页面已验证确认弹窗及取消，现有链接没有被删除。

1.7.0–1.7.3 更新渠道概览及渠道字段：Net revenue 改为 Refunds，汇总所选下单周期内的部分/全额退款；原有 paid revenue 口径保持。前四项统计合成一个卡片，桌面四列、窄屏两列，移除重复的 Overview/Reporting period 标题。渠道表增加 `commission_rate decimal(5,4) NOT NULL DEFAULT 0.2000`，数据库版本 1.1.0；后台创建默认 0.2（20%），编辑自动回填，校验 0–1、最多四位小数，旧表单遗漏此字段时保留现值；此版本仅保存配置，未计算或支付佣金。推广链接增加 Copy/Copied 按钮及不支持剪贴板时的选中/手动复制提示。日期选项为 Today、Yesterday、7 days、30 days、90 days、All time（站主最终要求保留 90 days）；手机端保持单行，超出屏幕可横向滑动，初始化时选中项自动滚动到可见位置。当天/昨天按 WordPress 网站时区 +08:00 的自然日计算；修复 PHP 数字键与字符串参数严格比较导致 7/30/90 不高亮的问题，选中项深绿底白字。升级前完整备份为服务器 `/root/trovesia-backups/20261029T173351Z`。

`tests/channel-dashboard-self-test.php` 的 25 项线上检查已通过，覆盖真实管理员保存入口、默认比例/四位精度/边界/非法输入、编辑回填、全额及部分退款、日期边界、数值范围高亮与门户结构。临时账号、渠道、链接、转换及审计数据均已清理，未创建真实订单。浏览器已核对分组卡片、Refunds、标题移除、日期高亮及 Copy 点击成功状态。

1.7.3 已完成线上部署及安全检查（`check-20261029T174720Z-2919807.log`）。390px 手机视口验收：六个日期选项处于同一行，导航内容宽 454px、容器宽 341px，可横向滑动；选择 All time 后当前项自动保持可见，7 days 深绿底白字，页面无横向溢出。四项统计在同一卡片内显示为两列，金额卡片并排。验收后已恢复浏览器默认视口。

## 4. 已启用的主要插件（2026-10-29 核对）

| 插件 | 版本 | 用途 |
| --- | ---: | --- |
| `woocommerce` | 11.1.2 | 商品、购物车、订单、结账；2026-10-29 官方补丁更新 |
| `woocommerce-paypal-payments` | 4.1.3 | PayPal 收款与快捷支付按钮 |
| `woocommerce-google-analytics-integration` | 2.4.4 | WooCommerce/GA4 统计 |
| `redis-cache` | 2.8.0 | WordPress 对象缓存 |
| `fluent-smtp` | 2.4.1 | 事务邮件投递 |
| `wp-media-folder` | 5.5.2 | 站主确认安装并要求恢复，已启用并纳入代码基线 |
| `two-factor` | 0.17.0 | 官方管理员双重验证 |
| `trovesia-security` | 1.2.0（MU） | 登录限速、代码修改策略及安全审计；管理员 IP 由 Cloudflare 控制 |
| `trovesia-plugin` | 3.8.1 | 本站自定义业务功能 |

当前主题 `trovesia` 版本为 2.9.2。

2026-10-30 主题 2.9.0–2.9.2 统一个人中心展示：账户页移除普通内容页的大白框，增加品牌页头；桌面侧边导航、手机单行横滑导航及当前项自动可见；首页以原生账户菜单为依据显示常用入口卡片。新增 `assets/css/account.css`，仅账户页加载，覆盖订单/订单详情、下载空状态、地址列表及账单/收货地址编辑、支付方式及添加支付方式、账户资料、登录/注册和密码恢复表单。原生数据查询、表单 nonce、保存及支付网关 iframe 保持使用 WooCommerce 实现；首页模板覆盖为 `woocommerce/myaccount/dashboard.php`，保留原 dashboard 扩展 hooks。

首次新增模板后须清理 WooCommerce 模板路径缓存：`wp eval 'wc_clear_template_cache();'`，否则 Redis 可能继续引用插件默认首页。页面布局只在存在账户导航时使用两列，避免已登录用户访问 lost-password 时表单挤到侧栏宽度。内容区内的持久信息（无下载、支付方式为空、邮箱验证说明）保留为内嵌内容，`woocommerce-notices-wrapper` 内的操作成功/失败提示仍使用原可关闭通知。

2.9.2 修复账户原生密码输入框眼睛图标：使用 `top:50%` 和 `translateY(-50%)`，44px 按钮内 flex 居中，避免原固定 top 与 WooCommerce 自带平移叠加；登录/注册、账户改密、密码重置共享此样式。线上三个改密框在桌面、390px 手机视口和显示/隐藏状态下测量中心偏移均为 0px，匿名登录/注册的两个图标也为 0px。

已通过 PHP/JavaScript 语法及 Git 空白检查；真实会话逐页核对主要五个栏目及订单详情、账单/收货编辑、添加支付方式，390px 无横向页面溢出；登录/注册使用无 Cookie 的线上 HTML 本地预览核对双栏/单栏，未提交表单；线上密码恢复表单桌面宽 560px。未保存资料、改密码或添加支付方式。主题通过原部署脚本三轮门禁发布，最终安全报告为 `security-reports/check-20261030T031644Z-3349014.log`，最终部署备份标记 `20261030T031521Z`。截图与验收记录在 `qa/account-20261030/`。

2026-10-28 优化 Checkout Block 支付选项样式：PayPal 与其他支付选项采用独立圆角卡片，品牌绿色选中态、20px 单选框、左对齐紧凑说明及手机端间距。样式仅限定在结账 `#payment-method`，位于 `theme/trovesia/assets/css/woocommerce.css`，保留官方网关表单与 SDK 按钮。CSS 使用文件修改时间作为资源版本；此次只部署该 CSS，原线上文件备份为 `deploy-backups/woocommerce-payment-20261028.css`。

2026-10-27 在全站页脚 `Customer care` 栏目的 `My account` 下方增加 `Channel partner login ↗` 入口，目标为 `/channel-portal/`。该入口使用分隔线和弱强调色，方便渠道商发现，同时避免占用普通顾客的主导航位置。

2026-10-27 将 WooCommerce 的成功、信息及错误提示统一改为右上角悬浮通知。通知支持手动关闭、多条垂直堆叠，鼠标悬停或键盘聚焦时暂停倒计时；成功/普通提示默认 18 秒后关闭，错误提示默认 30 秒后关闭，移动端自动适配屏幕宽度。

当前线上 GA4 Measurement ID 为：

```text
G-M8186Y45Q9
```

之前曾提供过 `G-E53FPW111Q`，但 2026-10-26 线上 WooCommerce Google Analytics 设置实际读取到的是 `G-M8186Y45Q9`。任何调整前先在后台或 WP-CLI 再核对，不要同时加载两个 Measurement ID 造成重复统计。

## 5. 当前商品快照

以下仅是 2026-10-27 的核对结果。价格和库存属于动态数据，修改前应重新查询。

| ID | 商品 | 类型 | SKU | 发布状态 | 目录可见性 | 当前价格 |
| ---: | --- | --- | --- | --- | --- | ---: |
| 20 | Advanced Batana Hair Regrowth Therapy | variable | `WBX8FPF` | private | visible | 从 $35.95 起 |
| 25 | Authentic Derma Roller | simple | `1775139057712537603` | private | visible | $23.61，原价 $47.22 |
| 78 | Scalp Serum Applicator Comb | variable | `TV-COMB-1079994456070` | private | visible | $24.90，原价 $34.90 |
| 17 | Advanced Batana Hair Regrowth Therapy — 2 Bottle Bundle | simple | `TV-BATANA-2B-ADDON` | private | hidden | $71.95 基础价；add-on 规则可覆盖结算价 |
| 101 | Christmas Spark Atmosphere Wand Gift Set | variable | `TV-XMAS-SPARK-GIFT-SET` | publish | visible | $69.90，原价 $99.00 |
| 133 | Spark Atmosphere Wand Refill Packs | variable | `TV-SPARK-REFILL-PACKS` | publish | visible | $29.90–$45.00 |

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
| 102 | Silver | `TV-XMAS-GIFT-SILVER` | $69.90，原价 $99.00 |
| 103 | Forest Green | `TV-XMAS-GIFT-GREEN` | $69.90，原价 $99.00 |
| 104 | Black | `TV-XMAS-GIFT-BLACK` | $69.90，原价 $99.00 |
| 105 | Pink | `TV-XMAS-GIFT-PINK` | $69.90，原价 $99.00 |
| 106 | Yellow | `TV-XMAS-GIFT-YELLOW` | $69.90，原价 $99.00 |

### 5.1 圣诞氛围棒礼盒的当前配置

- 正式页面：`https://www.trovesia.com/products/christmas-spark-atmosphere-wand-gift-set/`。
- 售卖单位是完整礼盒，不单卖氛围棒；礼盒含氛围棒、礼盒包装、一个材料盒和一小罐高压气罐。
- 商品没有功率参数，所有商品文案不得出现 `1000W`、瓦数或其他功率描述。
- 2026-10-27 线上售价快照为 USD $69.90，原价 $99；五个颜色变体均有独立英文礼盒内容图，默认颜色为 Pink。
- 运输时间为 8–15 天。商品页的动态预计送达日期也按当前日期加 8 天至加 15 天计算。
- 高压气罐须保留运输限制和户外使用安全提示；具体承运资格可能因目的地而异。
- 导入脚本：`scripts/import-christmas-spark-wand.php`。
- 首页更新脚本：`scripts/update-christmas-storefront.php`。
- 原始和处理图片：`product-assets/focus-spark-atmosphere-wand/source/` 与 `product-assets/focus-spark-atmosphere-wand/ready/`。
- d1–d3 详情图已本地化为 `ready/d1-en.png`、`ready/d2-en.png`、`ready/d3-en.png`，对应线上附件 ID 115–117；图片相册现有 8 张 gallery 图片，连同主图共 9 张。
- 详情图本地化脚本：`scripts/localize-focus-detail-images.py`。它只替换说明文字，保留产品、技术图和真实包装印刷内容。
- 首页 page ID 57 的桌面/手机横幅与圣诞文案已更新；2026-10-27 匿名首页验收时主菜单 `Shop Now` 指向 `/shop/`。
- 首页商品模块从主题 2.8.0 起按 WooCommerce 商品标签动态筛选，不再保存固定商品 ID。当前标签 slug 为 `christmas`，模块标题为 `Festive finds for every celebration.`，说明为 `Explore seasonal gifts and celebration essentials selected for Christmas.`，不包含统一价格。商品 ID 101 和 133 当前都带有 `christmas` 标签，因此都会显示；以后给已发布且目录可见的商品添加或移除该标签即可控制首页显示。

### 5.2 圣诞氛围棒耗材加购

2026-10-27 已把两类耗材合并为一个可单独购买的公开可变商品 ID 133：`Spark Atmosphere Wand Refill Packs`。它使用单一 `Refill Option` 属性展开六个变体，正式页面为 `https://www.trovesia.com/products/spark-atmosphere-wand-refill-packs/`。

| 变体 ID | SKU | Refill Option | 售价 |
| ---: | --- | --- | ---: |
| 134 | `TV-SPARK-REFILL-CHAMBER-2` | Material Chambers — 2 Pack | $29.90 |
| 135 | `TV-SPARK-REFILL-CHAMBER-4` | Material Chambers — 4 Pack | $39.90 |
| 136 | `TV-SPARK-REFILL-CHAMBER-6` | Material Chambers — 6 Pack | $45.00 |
| 137 | `TV-SPARK-REFILL-BUTANE-2` | Butane Gas Canisters — 2 Pack | $29.90 |
| 138 | `TV-SPARK-REFILL-BUTANE-4` | Butane Gas Canisters — 4 Pack | $39.90 |
| 139 | `TV-SPARK-REFILL-BUTANE-6` | Butane Gas Canisters — 6 Pack | $45.00 |

- 旧的临时简单商品 ID 127–132 已按用户确认永久删除；新站没有需要兼容的旧订单。
- 燃料舱按供应商包装标识写为每个约 45 g（±5 g），不写功率。
- 燃料罐明确为每罐 200 mL 的高压丁烷气罐；商品页和主商品加购区均包含极易燃、加压容器及承运限制提示。
- 商品图为 `ready/replacement-material-chamber-en.png` 和 `ready/butane-gas-canister-en.png`；丁烷图已移除供应商二维码并加入英文安全标识。
- 幂等导入脚本为 `scripts/import-spark-refill-addons.php`。
- Product Add-ons 从插件 3.8.0 起可关联 simple product 或某一个具体 product variation。后台选择器会展开可变商品的各个变体；若要提供多个变体，必须分别新增多条 linked-product 规则。购物车会使用变体父商品 ID、变体 ID 及属性值创建正确的独立订单行。
- add-on 规则还支持 `selection_group`、`group_title`、`group_description` 字段；相同 `selection_group` 的规则前台使用单选按钮，未分组规则仍保持原有复选行为。
- 线上验收已验证礼盒 $69.90 + 4-pack 燃料舱 $39.90 + 2-pack 丁烷罐 $29.90 可同时形成三条购物车行，合计 $139.70。

### 5.3 DIY 圣诞串珠树套装

2026-10-27 已发布可变商品 ID 149：`DIY Beaded Holiday Tree Craft Kit`，正式页面为 `https://www.trovesia.com/products/diy-beaded-holiday-tree-craft-kit/`。父商品 SKU 为 `TV-DIY-BEADED-HOLIDAY-TREE`，目录可见并带有 `christmas` 商品标签，因此会自动显示在首页 `Christmas Collection` 模块。

| 变体 ID | SKU | Style | 原价 | 促销价 |
| ---: | --- | --- | ---: | ---: |
| 150 | `TV-BEADED-TREE-A001` | A001 — Classic Christmas | $24.90 | $19.90 |
| 151 | `TV-BEADED-TREE-A002` | A002 — Winter Frost | $24.90 | $19.90 |
| 152 | `TV-BEADED-TREE-A003` | A003 — Faith & Peace | $24.90 | $19.90 |
| 153 | `TV-BEADED-TREE-A004` | A004 — Autumn Glow | $24.90 | $19.90 |
| 154 | `TV-BEADED-TREE-A005` | A005 — Halloween Glow | $24.90 | $19.90 |
| 155 | `TV-BEADED-TREE-RANDOM` | Random Style | $24.90 | $19.90 |

- `Random Style` 明确从 A001–A005 的现货款式中随机发一款，不保证指定款式。
- 英文化 DIY 套件拆解图以 `ready/diy-beaded-tree-kit-hero-en-webp.webp` 作为商品首图；1254×1254，约 333 KB，线上附件 ID 157。PNG 只作为本地母版保留，不在商品页或首页使用；之前上传的 PNG 附件 ID 156 已脱离商品引用但尚未永久删除。源素材 2–7 号图作为变体图和相册图，对应线上附件 ID 143–148。含中文促销文字的 1 号图只保留在本地源素材中，没有上传到英文站前台。
- 本地素材：`product-assets/diy-beaded-holiday-tree/source/` 与 `product-assets/diy-beaded-holiday-tree/ready/`。
- 幂等导入脚本：`scripts/import-diy-beaded-holiday-tree.php`。
- 商品运输说明为预计 5–10 天；商品 meta `_trovesia_delivery_min_days=5`、`_trovesia_delivery_max_days=10` 驱动动态预计送达日期。插件 3.8.1 起支持按商品覆盖动态日期，未设置时仍默认 8–15 天。描述包含小零件与成人监护提示，不承诺场景图中的环境灯光属于套装内容。

### 5.4 导液梳的已知特殊配置

- 正式页面路径：`https://www.trovesia.com/products/scalp-serum-applicator-comb/`；商品处于 `private` 时匿名访问会返回 404。
- 来源商品编号：`1079994456070`。
- 本地图片：`product-assets/1079994456070/`。
- `source/` 保留供应商原图；`retouched/`、`ready/` 是处理过程和上传成品；不要覆盖原图。
- 商品包含梳子、滴管、注液口塞和防尘盖；文案必须明确 `Liquid not included.`。
- Clear/Pink 采用展开式变体卡片，变体描述来自 WooCommerce variation description。
- 导入脚本：`scripts/import-scalp-applicator-comb.php`。
- 图片处理脚本：`scripts/render-scalp-applicator-images.py`。
- add-on 修复脚本：`scripts/repair-comb-addon.php`。

导液梳的 Batana add-on 目前仍采用隐藏的简单商品 ID 17。它最初是在 Product Add-ons 只支持 simple product 时创建的；插件 3.8.0 已支持直接关联某一个 product variation，但商品 17 仍有明确业务用途，除非用户另行确认，不要删除或迁移。

当前业务含义：

- 一份 add-on 商品代表一个两瓶套装。
- 主商品数量为 1 时，add-on 数量必须为 1，不能显示 2。
- add-on 固定促销价曾配置为 $29.90；该价格存在 `_trovesia_addon_rules` 中，使用前重新查询商品 78 的规则。
- `Quantity per main item` 应为 `1`。
- 如果限定颜色，`Allowed variation values` 必须精确匹配 `Clear`、`Pink`；留空表示所有变体。
- add-on 商品保持 `publish + hidden`，可购买但不在商店目录和搜索中单独展示。

### 5.4 迁移数据与线上数据的差异

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
- 部署前先检查线上权限、可信代码清单、MFA 和真实 FPM 健康；失败则禁止同步或重启。
- 在服务器 `deploy-backups/` 备份旧组件。
- 使用 rsync 更新指定主题/插件目录。
- 检查 Docker Compose 配置并重启相关容器。
- 执行健康检查和正式 URL 检查。
- 恢复 root 代码权限，登记授权版本清单并再次运行安全检查；报告保存在服务器 root 私有 `security-reports/`。

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

正式站优先使用服务器 root 的 `bash scripts/security-backup.sh`，额外包含 `.env`、`wp-config.php` 与部署配置，全部保存 `/root/trovesia-backups/` 私有目录。不要把这些敏感归档复制到本地仓库或上传公开目录。

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
- `trovesia-channel` 1.2.2 已在线上启用；本地仍有对应插件、Compose、Nginx、部署脚本和文档的未提交 Git 改动，后续提交时应作为同一功能变更处理。
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

