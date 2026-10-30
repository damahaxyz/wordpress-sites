# 个人中心样式验收

日期：2026-10-30（Asia/Taipei）。主题最终版本：2.9.2。

- 桌面 1280px：首页五个常用入口、侧栏选中态、独立内容卡片，无页面横向溢出。
- Orders、Downloads、Addresses、Payment methods、Account details：桌面与 390px 手机逐页检查；手机导航单行横滑，当前项自动可见，表单输入框处于页面内。
- Order details：手机订单详情表格和客户信息未溢出。
- Billing/Shipping address：手机表单、国家选择器和原生 nonce 存在，未保存数据。
- Add payment method：页面可打开、原网关 iframe 保留、手机无页面溢出；未输入支付信息。
- Login/Register：使用无 Cookie 线上 HTML 和线上资源作本地只读预览，桌面双栏、手机单栏；未提交表单。
- Lost password：真实线上恢复页面宽度由已登录时错误的 224px 修复为 560px；无页面溢出，未发送邮件。
- Password eye：账户当前密码、新密码、确认密码三个按钮，桌面/手机、显示/隐藏状态的按钮与输入框中心差值均为 0px；登录/注册两个按钮也为 0px。截图内密码保持遮罩。
- 保留账户表单、权限、密码及支付逻辑，仅调整主题展示和持久信息的排版。
- 语法、`git diff --check` 通过；最终部署前后安全门禁通过，报告 `check-20261030T031644Z-3349014.log`。

截图：`dashboard-desktop.png`、`dashboard-mobile.png`、`login-desktop.png`、`password-eye-mobile.png`。本地预览和临时服务已关闭，浏览器测试视口已恢复默认。
