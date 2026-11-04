# 弹窗密码按钮与商城退出验收

日期：2026-10-30（Asia/Taipei）。插件版本：3.8.2。

- Chrome 独立会话实际点击 My account 的 Log out：最终路径 `/`，body 无 logged-in 类，页面无 `#customer_login` 独立登录注册表单。
- 只读 PHP 检查：WooCommerce 默认退出目标与 `wc_logout_url()` 均为首页；渠道明确指定的门户目标和管理员明确指定的后台目标不变，四项通过。URL 检查先解码 HTML 实体后解析参数。
- 匿名 Shop 和 My account 使用无 Cookie 的正式 HTML 及正式资源作本地独立预览，避免干扰浏览器其他已登录会话。
- Shop 弹窗中 WooCommerce 自动追加的两个眼睛按钮均为 display:none；两个自定义文字按钮显示 Show。
- 登录和注册分别验证 Show → Hide → Show，输入框类型正确切换，未输入或提交密码。
- My account 页面弹窗无重复图标；独立原生登录注册表单的两个眼睛按钮仍可见，验证隐藏范围仅在弹窗。
- 390px 手机布局无弹窗横向溢出；截图 `login-modal-mobile.png`。退出后首页截图为 `logout-home.png`。
- PHP 语法、Git 空白检查、部署前后安全门禁通过。最终报告 `check-20261030T033506Z-3366625.log`。
- 页面缓存已清理；本地预览服务和标签页已关闭，临时视口已恢复。
