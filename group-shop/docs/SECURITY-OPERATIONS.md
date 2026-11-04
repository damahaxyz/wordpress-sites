# Trovesia 安全部署与维护

生效日期：2026-10-30（Asia/Taipei）。正式目录：服务器 `/root/wordpress-sites/trovesia-shop`；本地同名项目。事故证据见 [事件记录](incidents/2026-10-29-wordpress-compromise.md)。

## 管理员如何登录

使用 `https://www.trovesia.com/wp-login.php`，管理员来源由 Cloudflare 的访问规则控制。2026-10-29 按站主明确要求，将管理员两步验证改为用户可选，`damaha` 关闭两步验证；管理员不能用商城弹窗、渠道登录或 API 密码绕过原生登录及来源限制。普通客户登录保持原入口。

Two Factor 0.17.0 来自 WordPress.org，插件继续启用，个人资料的 Two-Factor Options 可以自行启用/关闭 Email、TOTP 等方式；不再自动补勾邮箱。验证器必须实际完成绑定才可使用，邮箱验证码需要邮箱/SMTP 正常。安全告警邮件独立保留，不会因关闭邮箱两步验证而关闭。

2026-10-30 按站主明确要求，MU 模块 1.2.0 删除服务器端管理员 IP 名单、原生登录 IP 拦截、管理员认证来源拦截及已登录管理员的后台/AJAX/REST 来源检查。`security/admin-access.json` 已归档移除；以后只在 Cloudflare 更改 IP，不再维护网站层副本，也不需要 Cloudflare API Token 或自动同步。

Cloudflare 仍需保护 `/wp-admin`、后台子路径和 `/wp-login.php`，保留前台 admin-post.php、admin-ajax.php 及 GET logout 例外。普通路径规则不能识别 WordPress 角色：公开 AJAX/REST 不再额外执行管理员 IP 检查。网站保留管理员只能从原生登录入口完成密码登录的规则、全入口失败限速和 API 密码限制。

验证脚本 `tests/security/cloudflare-admin-access-self-test.php` 通过私有容器网关确认 PHP 不再按来源阻断，测试专用管理员会话在 finally 中销毁，不改变现有密码/会话；公网 Cloudflare 拒绝非白名单的效果须单独核对。`tests/security/login-limiter-self-test.php` 验证六次失败后限制第七次，不使用真实账号。

密码登录：同一账号与来源 15 分钟内失败 6 次后限制；同一账号跨来源累计 30 次后限制。使用用户名/邮箱均对应同一用户。所有走 WordPress `wp_signon` 的密码入口均受检查；Redis 不可用时退回数据库 transient。管理员应用密码/API 密码登录已关闭。历史会话和认证 salts 已轮换，需重新登录。

## 每次部署必须经过的门禁

本地使用现有 `scripts/deploy-theme.sh`、`deploy-plugin.sh`、`deploy-channel-plugin.sh` 或 `deploy-all.sh`；统一进入 `deploy-component.sh`。安全配置单独使用 `scripts/deploy-security.sh`，渠道插件发布不再覆盖 Compose/Nginx。只调整监控/维护工具时使用 `scripts/deploy-security.sh --monitor-only`，仍有前后完整门禁和私有备份，但不重建网站容器、不重新生成代码清单、不注销管理员会话。安全部署不再无条件注销全部管理员；发现入侵或轮换认证 salts 时单独执行会话撤销。

流程固定为：本地语法检查 → 远端 `security-baseline.sh check` → 备份旧组件 → 同步明确组件 → 重启/健康检查 → 恢复权限 `apply` → 为已授权版本生成清单 `seal` → 再次 `check` → 清缓存/真实页面验证。

前置检查失败会在 rsync/重启之前退出。发现未知改动应先取证，禁止为使检查变绿直接 `seal`。部署有临时标记，监控跳过最多 10 分钟，正常/失败退出时清除；过期标记不能永久关闭检查。门禁的失败阻断与成功流程使用 `scripts/test-deploy-gates.sh` 的隔离替身验证，不发布工作区里无关的待审改动。

服务器只读检查：

```bash
cd /root/wordpress-sites/trovesia-shop
bash scripts/security-baseline.sh check
```

检查包含 Compose、只读挂载、真实 UID 33 写权限、root 所有权、完整代码 SHA-256 清单、插件白名单、可选 MFA 状态和网站层 IP 配置已移除、邮件配置解密校验、PHP 限制、真实 FPM 动态首页、Nginx 配置和危险 URL 404。root 私有报告位于 `security-reports/check-*.log`，可信清单为 `security-reports/code-manifest.sha256`。报告不会自动提交 Git。

## 后台 IP 白名单与接口分流（2026-10-29）

渠道插件 1.3.0 的账号创建和改密按下方分流。不能对整个 `/wp-admin/` 前缀统一拒绝非白名单 IP：

| 路径 | 当前用途 | 建议边界 |
| --- | --- | --- |
| `/wp-admin`、`/wp-admin/` 及其他后台页面 | 站点与商品管理 | 限定管理员公网 IP |
| `/wp-admin/admin-post.php` | 渠道登录/创建链接/暂停启用，评论、联系表单、订单查询 | 前台处理入口保留；按实际 action 与权限分流 |
| `/wp-admin/admin-ajax.php` | 商城登录/注册、购物车数量更新、渠道登录后修改自身密码及插件 AJAX | 前台处理入口保留；继续校验 WordPress 权限/nonce，不再额外检查管理员 IP |
| `/channel-portal/`、`/s/{token}/` | 渠道门户和推广跳转 | 保持公开可访问，门户数据继续登录/渠道隔离 |
| `/wp-login.php` | 管理员登录/双重验证、渠道退出及其他核心恢复流程；渠道新建账号不再发送原生设置密码链接 | 管理员登录单独限制，不能忽略退出/其他恢复用途 |
| `/my-account/lost-password/` | 线上渠道 Forgot password 链接实际经 WooCommerce 改写后的目标 | 保持正常账户恢复 |

渠道公开动作名：`trovesia_channel_login`、`trovesia_channel_create_link`、`trovesia_channel_toggle_link`、`trovesia_channel_delete_link`。链接管理检查登录、渠道链接权限、nonce 和所属渠道；删除额外只接受 POST，并在同一数据库更新中限定状态为 paused，标记 deleted 后保留历史统计且不能再次启用。登录检查 nonce 并调用受现有限速保护的 `wp_signon`。`wp-admin/includes/*.php` 的本地 require 是 PHP 包含文件，不是需要开放的 HTTP 路径。

渠道改密 AJAX 动作为 `trovesia_channel_change_password`，仅登录且有渠道权限及有效渠道关系的账号可用，校验 nonce、旧密码和两次新密码。后台初始密码与前台新密码均按站主要求至少 6 位；不生成统一共享默认密码、不发送设置密码邮件。成功后保留当前浏览器会话、撤销其他会话；旧密码错误累计 5 次则限制 15 分钟，密码不进入 URL 或审计记录。

当前由 Cloudflare 使用 `ip.src` 判断客户端 IP，宿主 Nginx 只限制可信 Cloudflare 回源网段，不维护管理员个人名单。Docker 后端看到的代理地址不能直接当管理员地址，也不能信任任意客户端自填的 X-Forwarded-For。公开共享入口不代表公开管理权限；普通路径规则无法按 WordPress 角色覆盖所有管理员 AJAX、admin-post 与 REST 请求。action 可能在 POST 正文中，不能只检查查询参数就认定允许。

管理员 IP 白名单仅由 Cloudflare 维护；网站层已移除重复配置。宿主 Nginx 保留仅允许 Cloudflare 回源，不能将普通路径白名单误解为已覆盖所有管理员 AJAX/REST 操作。

2026-10-30 已核对并发布两条 Cloudflare 自定义规则，顺序为公开入口 Skip → 后台 Block。Block 已补上此前遗漏的 `/wp-login.php`；三个原白名单 IP 保持不变。退出仅允许 GET、单个精确 `action=logout`，查询键限定为 `action`、`_wpnonce`、`redirect_to`；POST 退出、重复/伪装 action 不适用例外。完整表达式与验证证据见 [精简说明](SECURITY-SIMPLIFICATION.md)。以后新增必要的退出参数须先更新此例外。

## 权限基线

| 对象 | 所有者/权限 | 说明 |
| --- | --- | --- |
| 核心、plugins、themes、MU、languages | root；目录 755、文件 644 | 网站 UID 33 不得写入 |
| 自定义主题、两插件、MU、安全目录挂载 | Web 容器只读 | root 受控部署可更新宿主文件 |
| uploads、cache | 33:33；目录 755、文件 644 | 图片上传、图片评论和缓存需要写入 |
| wp-config.php | 0:33，640 | 网站可读不可写 |
| 宿主 .env | root，600 | 不输出、不同步到公共目录 |
| security-reports、备份与事件证据 | root 私有目录 700，敏感文件 600 | 不公开、不提交 |

后台禁止安装/上传/编辑代码及在线更新。插件激活/停用仍记录审计；强制安全规则由 MU 模块独立加载。上传目录禁止 PHP/PHTML/PHAR；Nginx 拒绝直接执行整个 wp-content 下的 PHP/PHTML/PHAR，`admin-ajax.php`、REST、支付回调保留正常路径。XML-RPC 返回 404。

PHP 禁用外部进程函数，FPM 单请求最多 120 秒，15 秒慢请求日志，每处理 500 请求回收 worker。健康检查实际请求 FPM 动态首页，不能只用配置语法成功代替业务健康。

## 安装与更新插件

获取官方可信安装包或官方源，先备份并检查基线；新增插件先审查 `security/plugin-allowlist.json`。只在服务器 root 维护窗口用以下包装器执行，后台/普通 WP-CLI 不允许写代码：

```bash
bash scripts/security-backup.sh
bash scripts/security-baseline.sh check
bash scripts/maintenance-wp.sh plugin update PLUGIN_SLUG --version=REVIEWED_VERSION
bash scripts/maintenance-wp.sh plugin verify-checksums PLUGIN_SLUG --strict
bash scripts/security-baseline.sh apply
bash scripts/security-baseline.sh seal
bash scripts/security-baseline.sh check
```

付费/自定义插件没有 WordPress.org checksum 不等于恶意，需与可信厂商包或受控源码比较。维护期间建立 `security-reports/deployment-in-progress`，结束清除；若操作超过 10 分钟须重新评估告警。PHP 配置发生变化后重建 web 容器，不能仅清页面缓存。普通商品/媒体数据导入继续使用 UID 33 的 WP-CLI，不需要 root 修改代码权限。

WP Media Folder 5.5.2 为站主确认主动安装的插件，2026-10-29 按明确要求恢复启用并纳入白名单/代码清单。样本静态扫描未发现已确认的后门；缺少该发行包的厂商原版比对，不能据此证明完全安全。厂商后续版本有权限检查修复，后续应使用站主的正版新包更新。两个 Maintenance Tools 后门不在白名单，继续隔离。

## 监控、告警和日志

`trovesia-security-watch.service` 每 5 分钟检查已有容器健康状态、一次绕过缓存的真实 HTTP 首页及认证审计；完整权限/哈希/插件/邮件校验改为约每小时一次（到期后下一轮执行）。失败的完整检查在下一轮重试；每次部署前后仍强制全量校验。失败/恢复状态变化发送一次通知；发送失败会重试。登录限制、被拒绝的管理员入口、管理员权限授予/移除、插件激活/停用及后台上传代码尝试触发安全提醒，最多每 30 分钟合并一次；普通顾客注册不触发权限告警。告警只发给现有 WordPress 管理员邮箱，不含原始日志/客户资料/凭据。

```bash
systemctl status trovesia-security-watch.service
journalctl -u trovesia-security-watch.service --since today
python3 scripts/security-watch.py --test-email
```

配置发信失败、数据库不可用时邮件可能延迟；整个服务器/网络故障时本地服务无法向外报告。这不是独立外部可用性监控。

结构化请求日志在 `runtime/security-logs/nginx/access.jsonl`，包含虚拟主机、可信反向代理来源、request ID、URI 路径和状态，不记录查询参数/正文/cookie。宿主代理必须覆盖 `X-Real-IP`，后端仅绑定 loopback，不可直接信任公网自填代理头。该访问日志包含来源 IP，按受保护运维资料处理。

认证审计在 WordPress 容器 stderr 的 `[trovesia-security]` JSON 中，记录用户 ID、来源 HMAC、事件/原因和 request ID，不记录密码、验证码、邮箱或支付密钥。Nginx 文件日志通过 logrotate 保留最多 30 份、压缩；Docker web/nginx 日志各最多 10 × 10 MB。保留期取决于流量，不能视为完整长期证据库。

## 备份与凭据

按站主要求，备份仅保存服务器 root 私有目录 `/root/trovesia-backups/`，不发送到外部存储。运行 `bash scripts/security-backup.sh`，生成数据库、wp-content、wp-config、.env 和部署配置，验证归档和 SHA-256 后才将 `.incomplete-*` 改为正式时间目录。未通过的半成品保留供 root 排查。此次已执行一次；未设置自动定时备份。

还原必须选择经过核验的版本，单独检查 SQL/代码和业务连接；事故现场 `/root/trovesia-incident-20261029/` 可能含恶意样本，禁止整体直接恢复。服务器同机备份不具备异地灾备能力。

此次官方补丁更新：Akismet 5.7 → 5.7.2、FluentSMTP 2.4.0 → 2.4.1、WooCommerce 11.1.0 → 11.1.2、Analytics 2.4.2 → 2.4.4；四项与 Two Factor 共五个插件通过官方严格校验。补丁前备份为 `/root/trovesia-backups/20261029T093820Z`。未进行支付插件的大版本更新。

已轮换数据库应用账号密码、WordPress 认证 salts，并撤销旧管理员会话。站主仍需更换独立强密码、确认邮箱安全，并在 Stripe/PayPal/SMTP 服务端轮换对应密钥；这些第三方密钥未代替站主重置。轮换后分别验证网关连接、webhook 与邮件送达，不能只检查页面显示。

### 认证 salts 与邮件加密兼容

FluentSMTP 默认用 `LOGGED_IN_KEY` / `LOGGED_IN_SALT` 加密 Gmail 客户端和部分令牌，直接 `config shuffle-salts` 会使旧密文无法解密。2026-10-29 已定位并修复：仅在 root 私有环境读取轮换前的 config（只做词法解析，不执行现场 PHP），先用原 `test` 哨兵验证旧密钥，再重新加密所有能验证的字段并保存私有回滚快照。没有恢复旧登录密钥或将凭据写入日志。

一次性修复脚本已从运行目录移至 `scripts/maintenance/rekey-fluent-smtp.php`，仅 root WP-CLI 可执行，旧 config 必须以只读文件挂载到 `/run/trovesia-pre-rotation-wp-config.php`，root 私有报告目录挂到 `/reports`；禁止从聊天复制密钥。未来轮换前先盘点依赖并备份，轮换后立刻迁移/验证 SMTP 加密，再实际测试发信。部署门禁现在也检查加密哨兵，防止再次将不能解密的配置判为正常。


## 运行目录精简（2026-10-30）

`security/` 保留 8 个运行/部署文件；WordPress 默认自动加载的只有 MU 主模块，其余由健康检查、监控或部署时调用。三份手工测试移到 `tests/security/`，SMTP 重加密工具移到 `scripts/maintenance/`，线上遗留 `inspect-mail.php` 仅留 root 私有归档。旧服务器 IP 配置已移除。文件职责与取舍见 [精简说明](SECURITY-SIMPLIFICATION.md)。

手工运行测试需按需挂载新位置，例如：

```bash
docker compose --profile tools run --rm -T -v "$PWD/tests/security:/tests:ro" wpcli eval-file /tests/cloudflare-admin-access-self-test.php
```
