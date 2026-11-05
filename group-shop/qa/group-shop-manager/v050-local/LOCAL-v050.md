# 0.5.0 本地单站多品牌验收

2026-11-04（Asia/Taipei）。用户明确授权本地关闭 Multisite，按“一套商城多个品牌门面”改造。**本地已完成，生产未发布、未连接。**

## 实际运行状态

- GSM_QA_MULTISITE=0，is_multisite()=false；原生 blog_id=1。
- 品牌 ID 1／6／7／9／11／12／13 与七个既有 www.main.local／branda／brandb／brandc／brande／brandf／brandg.local 域名保持。宿主 hosts、Apache、Docker DNS 均限定 loopback，HTTP80 → 18087。
- WordPress 7.0.4／PHP8.3／WooCommerce11.1.2／FluentSMTP2.4.1；原生 Woo 产品、用户、订单、Reviews 页面复用。
- 本地 Coming Soon 关闭，以便真实匿名前台测试；各品牌收录状态保留。MU 邮件／支付拦截保留，没有实际投递、收款、退款或生产请求。

## 迁移记录

[migration.json](migration.json)：7 品牌、67 商品＋89 变体、5 原始订单、17 评论（10 产品评论）、36 附件、90 页面、24 集合，生成41个公共父档案及相应公共变体。原生非主站 ID 重分配，精确映射保留；SKU 内部唯一，业务 SKU 单独保留。源商品／订单金额、地址、状态和文件保留，旧子站表、历史映射、审计和 uploads 不删除。

私有成套备份 `/private/tmp/group-shop-manager-qa/backups/single-brand-20261104-e59hs1sh/`：database.sql、source.tgz、qa.env、multisite-export.bin。不把私有导出、密码、令牌、重置链接或完整地址提交到证据目录。回滚必须同时恢复匹配数据库、源码与配置；不能只重开 Multisite。完整操作见 [OPERATIONS](../../../plugin/group-shop-manager/docs/OPERATIONS.md)。

## 自动与真实 HTTP 检查

| 报告 | 通过数 | 实际验证 |
|---|---:|---|
| [data-integrity.json](data-integrity.json) | 718 | 全部源商品／变体规格／价格／状态／图片、36文件、5订单与行及地址快照、集合／共享词表映射、页面／菜单引用、全部原评论作者／内容／审核、迁移幂等；清理后再次只读核对 |
| [runtime.json](runtime.json) | 28 | 单站品牌上下文／异常恢复、公共原生商品与品牌版本、继承与覆盖、可变商品、身份／权限／会话／REST |
| [http-single-brand.json](http-single-brand.json) | 30 | 真实 A／B 前台内容与价格、登录／复制Cookie、Store API token拒绝、购物车／优惠券／零支付区块结账、同名同邮箱注册、员工范围、原生商品编辑实际POST |
| [after-http.json](after-http.json) | 19 | 原生结账持久化、原价格129／数量1／总额0及无网关、订单品牌／顾客、B隔离、HTML／纯文本／直接重发品牌邮件、创建品牌不建站点、新品牌默认状态和页面 |
| [http-native-admin.json](http-native-admin.json) | 10 | 原生新增员工及资料授权实际POST、授权域登录、全局报表／设置拒绝、顾客角色伪造拒绝、签名品牌REST及篡改拒绝、真并发注册唯一 |
| [http-reset.json](http-reset.json) | 5 | B原密码可登录、A重置链接移至B拒绝、A原生重置成功、B密码和已有会话保留 |
| [security.json](security.json) | 27 | 品牌管理员无全局能力、自有页面可编辑／他牌拒绝、Shop Manager自有商品、原生新增草稿归属、元数据注入拒绝、实际员工授权落库、菜单／隐私页／分类计数／评论／私有集合范围 |
| [availability.json](availability.json) | 14 | 七品牌首页及原生账户页均200，账户登录表单正常 |

功能／数据检查 837 项，另可用性14项，共851项。28个生产PHP与全部测试PHP共60个文件语法通过，4个本轮Python脚本AST通过。测试准备／清理与业务操作副作用均限本地合成资料。旧 .4 及以前 Multisite 验收不直接运行。

原生区块结账：本地虚拟测试商品＋品牌专用100%优惠券，总额0，无付款网关；原生订单状态 processing 属正常待处理虚拟商品，不代表真实支付服务已接入。测试订单在持久化／邮件核对后删除，原有5单保持。

## 浏览器证据与清理

[单站多品牌概览](overview.png)：0.5.0、单站说明、六标签、品牌入口与普通单站后台。

[原生商品差异面板](native-brand-product.png)：A版本、公共档案关联、标题／描述／短描述／图片／价格覆盖控件；修正原生样式造成的双label重叠，最终截图控件可读。

[cleanup.json](cleanup.json)：仅清理本轮明确临时对象：12个失败fixture商品／变体、1张全额券、2笔零支付测试订单、8个临时注册／员工、1条遗留评论。原始记录截止1228，删除候选同时校验类型、前缀、所属、关联及保留白名单；未按裸ID批量删除。顾客／员工测试会话与重置key清理，根管理员会话保留。七域及718项源数据在清理后复验。

保留演示12条含变体记录：公共简单#1244→A#1245（独立129）、B#1246（继承79）；公共可变#1247→A#1250、B#1253。可在 [原生A编辑页](http://www.main.local/wp-admin/post.php?post=1245&action=edit&gsm_brand=6) 查看差异，公共档案不销售。不保留全额优惠券／测试订单；若重跑带写验收，须重新准备fixture并清理。

## 已知边界

一套商城共用主题代码、货币、税务、运费和支付配置，品牌管理员无这些全局权限；品牌Logo／展示选项／页面菜单／集合独立。无自动SSO、物理分库、真实支付／退款集成。源下载权限、付款令牌、税率和退款为零，不将空样本说成这些业务已完整验证。未知第三方会员／订阅／积分／自行SQL或全局邮箱解析须专项适配。

当前是特定受限 .local 快照显式迁移；未提供任意生产站或全新安装的一键初始化。生产必须另获授权、核对真实插件／数据，再单独制定维护、迁移、验收和成套回滚方案。旧Multisite写入入口已退休，保留旧表是档案，不是双运行。
