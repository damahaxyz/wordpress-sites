# Trovesia 安全功能精简

日期：2026-10-30，Asia/Taipei。根据站主要求：精简不必要的运行工具和重复巡检，管理员 IP 名单仅由 Cloudflare 维护。

## 保留的 8 个文件

| 文件 | 用途与运行时机 | 保留理由 |
| --- | --- | --- |
| `trovesia-security.php` | WordPress MU 模块；请求涉及认证/权限时执行对应 hook | 后台禁止写代码、失败登录限速、管理员原生登录及敏感事件审计，直接针对已确认的后台上传恶意插件链路。服务器端 IP 名单及拦截代码已删除。 |
| `filesystem-audit.php` | 部署前后及约每小时检查文件所有权/可写性、上传目录脚本和代码哈希 | 发现新增或篡改代码；不是每次访客访问扫描。 |
| `verify-wordpress.php` | 完整门禁时通过 WP-CLI 检查策略、插件名单和邮件配置 | 确认代码锁和插件状态符合部署要求；也防止邮件加密因 salts 变化损坏。 |
| `plugin-allowlist.json` | 安装插件目录名白名单，不包含版本钉死 | 检出类似 Maintenance Tools 的陌生插件；正常更新同名插件不需要改名单。 |
| `fpm-healthcheck.php` | 每分钟由 Docker 发起一次真实 FPM 首页检查 | 原事故中静态文件正常而 PHP 阻塞；单独 nginx -t 不能发现。 |
| `logrotate-trovesia.conf` | 系统 logrotate 定时使用 | 防止请求日志无限占用磁盘；不运行常驻 PHP。 |
| `send-alert.php` | 只在异常/恢复/重要认证事件通知时由 root 监控调用 | 保留站主指定管理员邮箱的通知，不在每次正常巡检时发信。 |
| `trovesia-security-watch.service` | 启动一个 Python 监控进程 | 每 5 分钟轻量健康/认证审计检查，完整门禁约每小时一次；失败状态变化告警。 |

这些不是 8 个独立的安全插件。WordPress 自动加载的是一个 MU 主模块；目录中的其他脚本不会因为放在这里就自动执行。

## 已退出运行目录的内容

| 原文件 | 处理 |
| --- | --- |
| `admin-access.json` | 服务器端管理员 IP 名单已移除；旧配置保存在 root 私有部署归档。以后只改 Cloudflare，不做双份名单或 API 同步。 |
| `admin-access-self-test.php` | 旧 IP 拦截测试已退役。`tests/security/cloudflare-admin-access-self-test.php` 验证 PHP 不再拦截来源，公网 Cloudflare 策略单独测试。 |
| `self-test.php` | 手工失败登录限速测试迁至 `tests/security/login-limiter-self-test.php`。 |
| `media-self-test.php` | 临时媒体上传/文件夹测试迁至 `tests/security/media-self-test.php`，只在相关改动时手工运行。 |
| `rekey-fluent-smtp.php` | 已完成的一次性修复工具迁至 `scripts/maintenance/`，不再挂入 Web 容器。未再次执行或更改 SMTP 凭据。 |
| 线上遗留 `inspect-mail.php` | 从运行目录移入 root 私有部署归档，不再挂入 Web 容器。 |

## 简化后的运行方式

- 每 5 分钟：读取已有容器健康状态、请求一次绕过缓存的本机 HTTP 首页、读取认证审计。常规轮询不再启动 WP-CLI 或扫描全部代码。
- 约每小时：运行一次完整权限/哈希/插件/邮件检查；失败后下一轮重试，而不是等下一小时。
- 每次部署：仍强制运行完整前置和后置门禁，失败即停止。
- 只更新监控/维护工具可用 `scripts/deploy-security.sh --monitor-only`，不用重建网站容器或重新封存未变化代码。完整安全配置更新仍使用默认模式。
- 不再在每次安全配置发布后无条件注销所有管理员；事故响应、密钥轮换时按需要单独撤销。

完整检查从原计划约 288 次/天降至约 24 次/天，不计部署/故障重试；这是调度次数减少，不代表 CPU 或 load 一定按同比例下降。

## Cloudflare 与网站的边界

站主明确要求删除网站层管理员 IP 限制：原生登录、管理员认证、后台/AJAX/REST 均不再读取服务器名单。请求来源仍用于失败登录计数和脱敏审计，不用于管理员白名单判断。两步验证由 Two Factor 自身和个人选项管理，不再强制邮箱/配置文件开关。

Cloudflare 需继续保护后台/原生登录入口并保留公开 AJAX、admin-post、退出例外；宿主 Nginx 保留仅接受 Cloudflare 回源。公开接口上已认证管理员的 IP 不再由应用额外限制，WordPress 原有身份、权限和 nonce 校验继续负责操作授权。源站保护范围没有扩展到服务器上其他站点。

### 已发布的 Cloudflare 规则

当前 Free 套餐使用兼容的 `starts_with`，不用 `matches` 正则。以后更换管理员 IP，只编辑第二条 `admin IP 加白`。

第一条 `wp-admin 开放路径`：动作 Skip，只勾选“所有其余自定义规则”，不跳过速率限制、托管 WAF 或 Bot 防护。

```text
(http.request.uri.path in {"/wp-admin/admin-post.php" "/wp-admin/admin-ajax.php"})
or
(http.request.uri.path eq "/wp-login.php"
 and http.request.method eq "GET"
 and len(http.request.uri.args["action"]) eq 1
 and http.request.uri.args["action"][0] eq "logout"
 and all(http.request.uri.args.names[*] in {"action" "_wpnonce" "redirect_to"}))
```

第二条 `admin IP 加白`：动作 Block。

```text
(not ip.src in {152.175.28.90 94.72.127.120 85.239.238.111}
 and (starts_with(http.request.uri.path, "/wp-admin")
      or starts_with(http.request.uri.path, "/wp-login.php")))
```

退出例外由原来的 URL 子串匹配收紧为单个精确 action，且只接受标准退出查询键，防止重名/编码参数利用。WordPress 仍校验退出 nonce。依据：[Cloudflare 数组参数字段](https://developers.cloudflare.com/ruleset-engine/rules-language/fields/reference/http.request.uri.args/)、[len 函数](https://developers.cloudflare.com/ruleset-engine/rules-language/functions/#len)。新增插件若需要其他原生登录动作或退出参数，必须单独评估；当前限制不会自动适配未来插件。

页面凭证：`qa/security-simplification-20261030/cloudflare-admin-access.png`。

## 验证与回滚

监控的 12 项隔离测试覆盖扫描调度、轻量故障、恢复、重试、部署延后、认证告警去重和 Docker JSON 格式。部署脚本替身测试确认前置失败阻断及 monitor-only 模式不重建网站/不改登录会话。

本次实际验证结果：

- MU 1.2.0 发布批次：`20261029T162454Z`（Asia/Taipei 为 2026-10-30）；部署完整前/后检查通过，报告为 `security-reports/check-20261029T162500Z-2835860.log`、`security-reports/check-20261029T162654Z-2838970.log`。
- 私有网关 11 项真实 HTTP 检查通过：模拟 IPv4/IPv6 来源访问原生登录、后台、管理员 AJAX/REST 不再受 PHP IP 名单阻断，渠道、购物车、有效退出仍正常。测试会话已销毁。
- 失败登录测试通过：六次失败后第七次受限；未使用真实账号或密码。
- 宿主机独立公网出口 14 项检查通过：非白名单后台和登录 GET/POST 返回 Cloudflare 403；有效 GET 退出返回 302；伪装/重复 action 与 POST 退出被拦截；首页、渠道、购物车正常，未指定 action 的公开 AJAX 按 WordPress 预期返回 400。
- 当前白名单工作站访问登录页返回 200。上述结果不是对三个 IP 分别进行端到端登录测试；三项名单以 CF 已发布表达式核对。
- 线上运行目录为 8 文件，监控服务 active；手工轮询输出 `healthy=True full_check=cached`，确认轻量周期不再重复全量扫描。

公网验证使用 `python3 tests/security/cloudflare-edge-self-test.py`，需从项目宿主机的非白名单公网出口运行；它生成无账号会话的访客退出 nonce，不修改账号。不能用容器内的 WordPress HTTP 调用结果代替独立公网请求来判断 Cloudflare 边界。完整结果摘要见 `qa/security-simplification-20261030/verification.json`。

部署前会将 `security`、脚本、Compose、Nginx、PHP 配置打包到 root 私有 `deploy-backups/security-<UTC>.tar.gz`；已退役文件保存在 `deploy-backups/security-tools-<UTC>/`。旧 IP 配置仅用于历史回滚，不应再次作为当前名单维护。

保留代码权限、后台编辑限制、备份和日志的依据：[WordPress 官方加固说明](https://developer.wordpress.org/advanced-administration/security/hardening/)。
