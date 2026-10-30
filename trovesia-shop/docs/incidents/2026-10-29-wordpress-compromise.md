# 2026-10-29 Trovesia 网站入侵与访问恢复记录

记录时区：Asia/Taipei。此文件不包含客户资料、账号凭据或恶意样本内容。

## 发现

- 用户报告首页/商品页打不开。公网访问及源站内部动态请求超时；静态 CSS 返回 200。
- Nginx 日志出现持续的 FastCGI upstream response-header timeout。
- WordPress PHP 容器存在 5 个 www-data（UID 33）权限的 Perl 进程，伪装为 syslogd、sshd、acpid、httpd；其中一个持续占用约一个 CPU 核心。
- 发现两个随机目录插件 `wp-3hppkwvobt`、`wp-4jtash8wf0`，均伪装为 Maintenance Tools。静态代码提供未经身份验证的系统命令/下载/运行入口，并可启动 `bog` Perl 载荷。
- 日志确认上述插件 PHP 曾被直接请求并返回 200；后续关联已确认后台登录→标准上传插件→直接执行后门的链路，见下文。文件时间不能作为独立入侵时间证据。
- 当时数据库和 Redis 正常，磁盘使用约 13%。

## 取证与可恢复隔离

服务器目录 `/root/trovesia-incident-20261029/` 采用 root 私有权限保存：

- `evidence/`：容器 inspect、docker diff、进程与网络连接记录、Nginx/PHP 日志、完整 webroot 和 tmp 归档、变更前配置。
- `backup/`：数据库、wp-content 备份及校验值（时间戳 `20261029T051818Z`）。
- `quarantine/`：两个恶意插件与被暂停的第三方媒体插件副本。

备份是事发现场资料，可能包含恶意文件和凭据，不能直接当成干净版本整体恢复；不得上传至公共目录或提交 Git。

## 已实施

1. 将两个恶意插件移出网站目录，重启 PHP 容器终止恶意进程。
2. 最初暂停并隔离第三方汉化 WP Media Folder 5.5.2。后续站主确认本人安装，并明确要求恢复；已按该指示纳入白名单并恢复。没有把它定性为已确认的恶意插件。
3. 销毁 5 个用户的旧登录会话，未修改密码、用户角色、商品或订单。管理员应用密码数量为 0。
4. 在 `nginx/conf.d/default.conf` 中拦截 `/wp-content/` 下 PHP/PHTML/PHAR 文件的直接公开执行；插件正常通过 WordPress 加载，REST/admin-ajax 路由保留。
5. 在 `php/uploads.ini` 中禁用 exec、passthru、shell_exec、system、proc_open、popen、pcntl_exec、pcntl_fork，关闭 allow_url_include、display_errors、expose_php。
6. 从现有镜像重建本站 WordPress 容器，清除受影响的临时容器层；保留数据库、上传文件和自定义代码卷。
7. 验证 Nginx 配置后 reload 并清除页面缓存。

## 核验

- WordPress 核心通过官方校验；`wp-config-docker.php` 为 Docker 镜像辅助文件，校验工具提示额外文件。
- 7 个 WordPress.org 插件通过严格校验。两个自定义插件及 premium 媒体插件无 WordPress.org 校验值；自定义主题和两插件逐文件 checksum dry-run 与本地一致。
- 未启用的 `hello.php` 存在官方 checksum 差异；静态检查为普通 Hello Dolly 代码，不把差异单独视为入侵证据。
- Redis `object-cache.php` 与已校验 redis-cache 插件提供的副本一致。
- 管理员仅 ID 1（注册于 2026-09-07）；未发现新增管理员、MU 插件或上传目录中的 PHP/PHTML/PHAR 文件。
- 首页、商店、商品页及 REST API 返回 200；匿名空购物车访问 checkout 返回正常 302。
- 现有浏览器购物车的 Checkout Block 正常加载，PayPal 和 Stripe 选项可见。未提交订单或付款。
- 两个恶意插件入口与上传目录 PHP 测试路径返回 404。
- 重建后仅见正常 PHP-FPM 进程，未再见上述 Perl 进程。后续已部署服务器本地持续检查及管理员邮件告警，见安全操作手册。
- 前台截图：`qa/recovery-20261029/product.jpg`、`qa/recovery-20261029/checkout.jpg`。

## 后续日志分析：已找到后台登录—上传插件的入口链路

对宿主机轮转访问日志及容器取证日志关联后，证据进一步明确。以下时间均为 Asia/Taipei（UTC+8），不是按日志轮转文件名推算日期。源日志不含登录 POST 正文和 WordPress 用户 ID，来源以匿名化标识 `8c437078be36` 表示；同一来源不等于已识别自然人。

| 日志时间 | 请求与状态 | 结论 |
| --- | --- | --- |
| 10-25 05:44:19 | POST `/wp-login.php` → 302 | 随后的受限页面访问支持登录成功，而非仅凭 302 判断 |
| 10-25 05:44:21–24 | GET `/wp-admin/`、`/wp-admin/plugin-install.php` → 200 | 已进入后台并可访问插件安装页面 |
| 10-25 05:44:27 | POST `/wp-admin/update.php?action=upload-plugin` → 200 | 使用 WordPress 标准插件上传流程 |
| 10-25 05:44:28 | GET `/wp-admin/plugins.php?action=activate&plugin=…` → 403 | 激活请求被拒绝，不代表之前落盘的 PHP 不能被直接执行 |
| 10-25 05:44:29 | GET `/wp-content/plugins/wp-4jtash8wf0/wp-4jtash8wf0.php` → 200 | 上传后两秒即可公开直接访问后门 |
| 10-25 06:45:23 | 上述后门 `task=download` → 200 | 通过后门下载载荷 |
| 10-28 03:08:26 | POST `/wp-login.php` → 302 | 同一来源再次进入登录流程 |
| 10-28 03:08:28–34 | 后台/插件安装页 → 200；POST `upload-plugin` → 200 | 同一后台上传模式再次出现 |
| 10-28 03:08:37 | GET `/wp-content/plugins/wp-3hppkwvobt/wp-3hppkwvobt.php` → 200 | 第二个后门可直接执行 |
| 10-28 03:29:50 及后续 | 第二后门 `task=download` → 200；多次 `task=run` → 499 | 对应下载及长时间运行的载荷请求 |

499 为客户端在请求完成前断开，不说明载荷没有执行；这些长请求的日志时间也不能直接当作进程开始时间。

**高可信结论：本次已观察到的恶意插件落盘路径是获得后台可安装插件权限后，走标准 `upload-plugin` 上传，再直接执行后门。** 激活失败仍可执行，是当时 Nginx 允许公开请求插件目录 PHP 的结果。相同链路在 10-25 和 10-28 重复；不是只由文件修改时间推断。

**仍未知：** 账号密码怎样被取得、是否有更早入侵、是否读取/外传资料。不能仅凭访问日志确定具体账号名或认定弱口令、撞库、浏览器窃密中的哪一种。当前仅保留一个管理员不能排除过去存在其他已删除账号。

**媒体插件的定位修正：** WP Media Folder 5.5.2 是站主确认主动安装的旧版包；按站主要求恢复使用。厂商在 5.7.3 等后续版本修补过权限检查，后续应使用正版新包更新。但现有攻击链不需要利用它，不能把它定为本次已证实入口。付费插件缺少 WordPress.org 校验值本身也不是恶意证据。厂商记录：https://www.joomunited.com/changelog/wp-media-folder-changelog

可复核脱敏结果：`qa/recovery-20261029/attack-timeline-anonymized.json`。只读关联脚本：`scripts/audit-incident-access.py`，支持指定日志文件；不输出原 IP、密码、nonce 值或命令载荷。

## 已部署的后续加固

- root 代码权限、自定义代码只读挂载、后台代码修改锁与受控 root 维护入口。
- 官方 Two Factor 0.17.0、管理员强制 MFA/原生登录、密码入口限速、管理员 API 密码登录限制。
- 插件白名单、代码 SHA-256 基线、每次发布前后强制安全检查，失败阻断。
- 真实 FPM 动态首页健康检查、请求时限与慢日志、结构化登录/权限/插件审计、request ID 和路径日志、日志轮转。
- 本地每五分钟检查及状态变化提醒，接收方为现有 WordPress 管理员邮箱。备份按站主选择保留 root 私有目录。
- 已轮换数据库应用密码和 WordPress 认证 salts、撤销旧会话；第三方支付/SMTP 密钥与管理员主密码仍由站主轮换。
- 已更新四个官方补丁（Akismet、FluentSMTP、WooCommerce、Analytics），含 Two Factor 的五插件严格官方校验通过。WP Media Folder 恢复后的图片导入、目录创建和分类测试通过，临时媒体/分类已清理。
- 真实负向测试证明：UID 33 可写的代码文件被权限检查拒绝，新增只读代码文件被完整性清单拒绝；测试文件已清理，随后完整检查通过。随机不存在账号的第七次失败登录被限速，没有使用真实账号。
- 公网 HTTPS 首页、商品、登录、匿名购物车和结账 HTML 验证通过，Store API 返回 PayPal/Stripe 网关，临时购物车清空；未提交订单/付款。此次浏览器工具不可用，未声明补丁后视觉/支付完成流程已验证。
- 测试发现登录 salts 轮换使 FluentSMTP 原 Gmail 配置无法解密；已用 root 私有旧 config 的词法解析结果重新加密四个字段，保留新 salts，Gmail 发信调用恢复成功。门禁增加解密检查；实际收件待站主确认。

操作与部署检查清单见 [安全维护手册](../SECURITY-OPERATIONS.md)。

后续站主明确要求在 IP 白名单保护下可关闭两步验证。MU 安全模块 1.1.0 将 MFA 改为个人可选，`damaha` 当前关闭；不再强制补勾邮箱。同时网站层增加三个指定 IP 的原生登录及管理员认证/后台/AJAX/REST 检查，覆盖 Cloudflare 前台共享接口例外。原先的“管理员强制邮箱 MFA”是加固初始状态，当前策略以安全维护手册为准。

## 落实范围

上述加固覆盖受控发布、代码权限、MFA、限速、审计和动态健康检查。独立外部监控及异地备份未启用，备份依站主指示保留本机；第三方密钥轮换与管理员主密码更换仍需站主完成。

## 仍需处理的安全事项

- 最初凭据泄露原因与数据是否外传仍未确定；后台上传恶意插件的链路现已由历史日志证实。此次恢复/校验不是完整司法取证，也不能证明所有凭据未暴露。
- 站主应更换 WordPress 管理员密码，并在支付/邮件服务控制台轮换 Stripe、PayPal、SMTP 凭据；更新后需要再核对网关连接与 webhook。当前未擅自更改第三方服务凭据。
- WP Media Folder 已按站主明确要求恢复。355 个文件中 83 个 PHP 文件经过只读静态及词法检查，未发现本次后门标记或危险执行/解码调用；一个动态类构造位于通用更新检查库。两个 Maintenance Tools 样本各有 13 处实际进程执行函数调用。样本扫描不是安全认证，缺少厂商同版原包仍不能证明没有任何改动。脱敏结果和文件哈希见 `qa/recovery-20261029/wp-media-folder-static-review.json`。
- 对其余官方插件安排有备份、可回退的更新；不要在修复访问期间盲目升级全部业务插件。
