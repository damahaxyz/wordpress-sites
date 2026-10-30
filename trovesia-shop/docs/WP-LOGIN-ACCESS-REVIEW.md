# wp-login.php 白名单影响核查

日期：2026-10-29，Asia/Taipei。此次为只读核查，未修改 Cloudflare 规则，也未发起真实付款、第三方授权或给顾客发送测试邮件。

> 2026-10-30 更新：站主已要求将管理员 IP 限制统一交给 Cloudflare，网站层 IP 检查已移除。本文件的插件/核心依赖分析仍适用，当前部署策略以 `SECURITY-OPERATIONS.md` 为准。

## 范围与结论

前一轮重点验证渠道和顾客账户流程，未逐一核查全部插件。本轮补查线上实际启用的 10 个插件及 Trovesia 主题的源码引用，并核对当前账户/内容设置。未启用的插件、未来插件版本和所有第三方授权场景不属于已验证范围。

在保留 admin-ajax.php、admin-post.php 公开入口，且对 wp-login.php 保留 GET + action 精确为 logout 的例外时，未发现当前主要前台交易/渠道流程新增的阻断。不能把此结论扩展为 WordPress 所有功能都不受影响。

## 当前启用插件

| 组件 | 版本 | 核查结果 |
| --- | --- | --- |
| Trovesia Channel | 1.3.0 | 登录为 admin-post.php，改密为 admin-ajax.php，新渠道创建不再发送设密邮件；退出仍需 wp-login.php?action=logout。 |
| Trovesia Plugin | 3.8.1 | 顾客弹窗登录/注册为 AJAX，找回密码指向 WooCommerce 账户页；评论/联系/查询为 admin-post.php。 |
| WooCommerce | 11.1.2 | 当前账户、找回密码、新顾客设密邮件使用 /my-account/；退出经 wp_logout_url，仍需退出例外。 |
| WooCommerce PayPal Payments | 4.1.3 | 源码未发现对原生登录页的直接调用；支付通知和结账使用自身接口。 |
| WooCommerce Stripe Gateway | 11.1.1 | 源码未发现对原生登录页的直接调用；webhook 挂载 woocommerce_api_wc_stripe。 |
| FluentSMTP | 2.4.1 | 未发现原生登录页直接依赖；后台维护及授权若会话过期，仍需从白名单重新登录。 |
| WooCommerce Google Analytics Integration | 2.4.4 | 未发现原生登录页直接依赖。 |
| Redis Object Cache | 2.8.0 | 未发现原生登录页直接依赖。 |
| WP Media Folder | 5.5.2 | 未发现原生登录页直接依赖；后台使用仍受后台白名单和权限约束。 |
| Two Factor | 0.17.0 | 验证/恢复入口依赖 wp-login.php，管理员从白名单完成该流程符合当前策略；以后给非白名单普通用户启用时需重新评估。 |
| Trovesia 主题 | 2.8.0 | 未发现直接调用原生登录页的额外入口。 |

WooCommerce 的 Blocks 和下载功能保留原生登录页作为“没有 My account 页面”时的后备地址。当前已配置 My account，Blocks 优先使用该页面，下载处理同样优先跳转该页面；本站弹窗脚本同时接管结账登录提示。删除/更换账户页或启用新的单点登录流程后需再次检查。

## 会受限制的核心功能

| 功能 | 原生路径 | 当前状态/影响 |
| --- | --- | --- |
| 密码保护文章或页面 | POST wp-login.php?action=postpass | 当前已发布且设访问密码的内容为 0；以后使用时，非白名单访客无法提交访问密码。 |
| 隐私数据导出/删除确认 | wp-login.php?action=confirmaction | 当前隐私请求为 0；以后向普通用户发确认邮件，需放开安全确认流程或迁移到前台。 |
| 原生找回/设密、后台新建其他账号邮件 | lostpassword/retrievepassword/rp/resetpass | 原生非白名单流程会被阻止；本次渠道专用创建流程不再使用它，但不能据此认为其他原生通知也已迁移。 |
| 原生注册、普通登录、登录过期重新认证 | register/login/reauth 等 | 原生注册当前关闭，前台使用 WooCommerce/自定义入口；原生入口只对白名单开放。 |
| 顾客和渠道退出 | GET wp-login.php?action=logout | 必须保留例外；继续由 WordPress 校验 log-out nonce。 |

当前原生注册关闭，原生评论无需登录；WooCommerce 账户注册与结账注册已开启。顾客新账号邮件的 generate_set_password_url() 及密码恢复邮件均使用 WooCommerce lost-password endpoint，不是原生 rp/resetpass 页面。

## 配置边界

- IP 非白名单与敏感路径条件必须使用 and，不能用 or，否则会阻止普通访客访问全站。
- 正则 `^/(wp-admin|wp-login\.php)` 表示两个前缀；它也会覆盖同名前缀的其他路径。仅需实际后台目录和原生登录文件时，可用 `^/(wp-admin(/|$)|wp-login\.php$)` 收窄。
- Cloudflare 的 matches 正则运算符需要 Business/Enterprise；界面显示选项不等于当前套餐一定可发布。
- 退出例外应限制 GET、精确路径和精确 action；不要只检查 URI 中包含 action=logout。网站层现有实际 action/IP 校验继续作为补充。
- 限制 wp-login.php 不代替前台密码入口限速；商城和渠道登录仍能接收普通用户的密码。当前这些 wp_signon 入口已有全局失败次数限制及管理员原生入口/IP 限制。
- 修改规则后应从非白名单再次验证：首页/商品、顾客与渠道登录、两种退出、找回密码、结账登录和账户设密；支付是否成功需另做受控支付测试。

完整源码命中记录仅保存在服务器 root 私有文件 `security-reports/wp-login-dependency-review-20261029.json`。

参考：

- https://developers.cloudflare.com/ruleset-engine/rules-language/operators/
- https://developer.wordpress.org/reference/functions/get_the_password_form/
- https://developer.wordpress.org/reference/functions/wp_logout_url/
