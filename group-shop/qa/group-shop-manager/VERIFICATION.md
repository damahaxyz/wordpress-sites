# Group Shop Manager 0.1.0 最终交付验收

日期：2026-11-03（Asia/Taipei）。线上项目：`root@contabo2:/root/wordpress-sites/group-shop`。

## 已交付状态

- 已完成 WordPress Multisite 迁移，Group Shop Manager **0.1.0** 网络启用。
- WooCommerce **11.1.2**、FluentSMTP **2.4.1** 网络启用；主店原有业务插件保留。
- 管理入口：<https://www.animecats.online/wp-admin/admin.php?page=group-shop-manager>。
- 原管理员权限升级为网络超级管理员，密码未改。主店保留 6 个用户与 4 笔测试订单；验收临时用户已删除。
- 演示分店 blog_id=2：<https://www.animecats.online/demo-store/>，禁止收录、客户邮件关闭。分店商品 18 来自主店商品 175，保持独立草稿与零库存；未复制订单或评论。
- 独立 trovesia-shop 未加入网络，本轮未修改该项目或其线上站点。

## 验证证据

- PHP / shell 语法与既有部署门禁通过。
- 独立真实 WordPress QA：**76 项通过**，见 [integration-20261103.json](integration-20261103.json)。服务器原件 `/root/wordpress-sites/group-shop-manager-qa/qa-report.json`。
- 浏览器验证：管理员登录、中央订单来源与筛选、状态表单 nonce 提交、受限状态转换、订单详情及内部备注入口。
- 线上安装验收：**22 项通过**，见 [production-acceptance-20261103.json](production-acceptance-20261103.json)。服务器原件 `security-reports/gsm-acceptance-20261103.json`。
- 最后代码与文档同步后，恢复 root 所有权和只读挂载，建立授权代码 manifest，完整安全基线通过，退出码 0：`/root/wordpress-sites/group-shop/security-reports/check-20261102T174039Z-1104410.log`。
- 公网以下页面全部 HTTP 200：`/`、`/shop/`、`/cart/`、`/my-account/`、`/demo-store/`、`/demo-store/my-account/`、`/products/2026-firework-bubble-machine-with-ambient-lights/`。
- 主店和分店前缀下的直接插件 PHP、上传 PHP 执行入口均被阻止（404）。Nginx 配置验证通过。
- 数据库、Redis、WordPress、Nginx 四个线上容器均 healthy；监控单次检查 `healthy=True`。部署标记已移除，页面缓存已清理。
- QA 容器已停止，独立卷保留，SSH 浏览器测试隧道已关闭。QA 邮件拦截 MU 插件未安装到生产。
- 本轮没有实际投递客户邮件、执行收款或新建线上测试订单。

## 备份与安装包

- 迁移前完整私有备份：`/root/group-shop-backups/20261102T171948Z`，归档及 SHA-256 验证通过。
- 快速配置备份：`/root/wordpress-sites/group-shop/deploy-backups/gsm-v010-pre-multisite/`。
- 插件安装包：`dist/group-shop-manager-0.1.0.zip`，18 个文件，包含插件源码、AGENTS 和全部插件说明，不包含凭据、数据库或测试数据。
- 首次交付安装包 SHA-256（路由修复前历史包）：`42310c4d74b528fbddb66d9aed27375b524cd244c3e0302c799629c75829171d`。随后因维护文档更新重新打包，当前包以 `dist/group-shop-manager-0.1.0.zip.sha256` 为准。
- 当前生产已有只读挂载与 Multisite 配置，不能用上传 ZIP 替代服务器部署流程；后续部署使用 `scripts/deploy-manager-plugin.sh`。
- 回滚与操作规则见 `plugin/group-shop-manager/docs/OPERATIONS.md`。停用插件不会撤销 Multisite。

## 使用前需明确

第一版共享账户、同一密码与默认账单／收货地址；不同独立域名分别登录。商品、分类、价格、排序、库存、评论和前台订单按店隔离。支付、退款、共享付款方式、自动 SSO、自动 DNS/HTTPS、自动差异化文案不在本版。

FluentSMTP 未修改核心；各店启用邮件前，须在主店配置精确发件路由并完成真实投递验证。被阻止邮件不排队、不补发。独立域名上线、品牌页面和既有政策文本仍需逐店配置与检查，不能承诺搜索引擎无法识别关联。

新对话从 `plugin/group-shop-manager/docs/HANDOFF.md` 开始。根目录与插件目录的 `AGENTS.md` 均要求功能、限制和部署变化同步更新文档。

## 首次验收遗漏及后续修复

用户随后报告后台循环重定向；此前 CLI 管理页渲染未覆盖真实后台 HTTP 入口，前台 200 也不能代表后台正常。现已修复 Nginx 对真实后台 PHP 文件的误重写，补充未登录及已登录的 HTTP 回归。修复后状态与证据以 [REDIRECT-FIX.md](REDIRECT-FIX.md) 为准。
