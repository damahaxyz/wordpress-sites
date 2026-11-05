# 单站多品牌架构（0.5.9，本地）

## 基本模型

只有一套原生 WordPress 表和 WooCommerce 存储。品牌由 `gsm_brands` option 注册；保留历史编号 1／6／7／9／11／12／13 作为品牌 ID。运行时原生 `get_current_blog_id()` 恒为 1，品牌编号不能再从 blog_id 推断。品牌 UUID 保持，`gsm_brand_sequence` 单调递增，不复用已分配编号。

`Brands::id()` 为当前品牌；`Stores::within()`／`Brands::within()` 只改变受控业务作用域，finally 恢复，不切换原生表。`Brands::unscoped()` 仅供受信内部批量操作，不是外部授权接口。网页按注册 Host 精确识别品牌，未知 Host 拒绝；前台顾客不能用查询参数选择其他品牌。

## 数据权威

| 数据 | 原生存储及扩展 | 原则 |
|---|---|---|
| 品牌 | `gsm_brands`；`gsm_brand_options_{id}` | 域名、名称、UUID；品牌资料／页面 ID／集合规则／主题展示选项独立 |
| 公共商品 | 原生 product／product_variation，`_gsm_kind=base`、`_gsm_brand_id=0` | 不直接展示或销售 |
| 品牌商品 | 原生 product，`_gsm_kind=edition`、`_gsm_brand_id`、`_gsm_base_product` | 各版本有不同原生 ID，独立发布状态和排序 |
| 字段差异 | `_gsm_overrides`；变体 `_gsm_base_variation` | name／description／short_description／images／price 显式覆盖；取消即恢复继承 |
| 分类／标签／属性／媒体 | 单站原生 terms／关系／attachment | 共用 ID 与文件，无跨站投影或图片导入 |
| 商品集合 | 品牌 option `gsm_collections`＋带 `_gsm_collection`／品牌标记的原生分类 | 规则、回收站和成员独立，不把集合当共享普通分类 |
| 顾客资料 | 原生 users／usermeta，一个品牌一个 ID | 密码、邮箱、默认地址、会话继续原生；不同品牌可同邮箱 |
| 身份索引 | `gsm_identities` | 保留 network_id=1；顾客 store_id=品牌，员工 scope=0；规范化别名／邮箱分别唯一 |
| 员工授权 | 原生共享员工 ID＋`_gsm_brand_roles` | 品牌 Administrator／Shop Manager 白名单，不用全局 administrator 角色代替 |
| 总管理员 | option `gsm_platform_admins` | 受控 ID 清单；不能用单站 is_super_admin 推断品牌管理员 |
| 订单 | 原生 Woo CRUD，兼容 HPOS；`_gsm_store_id` 等来源快照 | 顾客必须属于订单品牌，员工不能作为购物顾客 |
| 评论 | 原生 comments／commentmeta `_gsm_brand_id` | 作者、评分与购买验证依附所属品牌销售商品，普通回复也保留归属 |
| 页面／文章／菜单 | 原生 posts、nav_menu terms，品牌元数据 | 公共 URL 使用 `_gsm_public_slug`，内部唯一 slug 允许不同品牌保留同路径 |
| 历史映射／审计 | 旧 `gsm_catalog`／`gsm_audit` | 保留历史，不再用旧映射驱动跨站写入；审计仅编号、动作、字段及计数 |

原生 SKU 在单站必须唯一：迁移时内部 SKU 命名空间化，原业务 SKU 保存 `_gsm_business_sku`。共享媒体仍引用原 uploads/sites/N 文件，未删除或移动原文件。原始 root 记录尽量保留 ID，其他站点导入重新分配 ID，准确映射存 `gsm_single_migration_map`，不能按数字相同推断归属。

## 公共商品与覆盖

`ProductVersions` 扩展 Woo 原生商品面板，保存公共商品时为已选择品牌建立原生草稿版本，更新已关联版本未覆盖字段。标题、长短描述、主图相册和价格／促销日程可独立；规格、税务类型、重量尺寸、下载配置及分类标签共用。变体按来源映射同步，允许自己的价格、图片、描述；父商品价格覆盖优先。公共删除的变体停用对应版本，不删除历史订单资料。

既有品牌独立增加过规格时，迁移按显式历史映射和实际规格形状分组；不强行把不同规格归入同一公共档案。原有标题／价格／图片差异自动标记覆盖，保留迁移前行为。公共商品不直接参与前台查询、购物车、成交、评论或品牌集合。

## 查询与权限

`BrandScope` 在原生 WP_Query、Woo 商品／订单 CRUD 查询、HPOS 列表、评论／用户列表及 REST 数字 ID／批量入口上施加品牌条件；直接商品、订单与页面编辑能力也校验归属。原生 Reviews 增加所属品牌列。共享分类树不复制，计数按当前品牌计算；菜单／页面和集合独立。公共档案及关联操作仅商城总管理员可管理。

总管理员在原生后台顶栏选择“全部品牌”可查询全商城；选择某品牌后原生列表按该品牌处理。Gutenberg／React 请求通过 nonce 签名的 X-GSM-Brand 头携带当前后台作用域，无签名／篡改／顾客伪造请求拒绝。普通 Host 上的顾客 REST 和 Store API 仍由域名决定品牌。该桥接不是自动 SSO。

员工只获得经营所需白名单能力：本品牌商品、订单、优惠券、顾客和审核；品牌 Administrator 另可编辑本品牌文章／页面。不能安装／更新／删除插件、改共享设置／主题、推广员工权限或访问全局销售报表。共享媒体可选用及上传，但员工不能永久删除共享附件或修改他人附件。员工不能改商品归属／公共来源元数据。总管理员编辑员工授权后撤销该员工会话。

顾客固定 Customer，禁止元数据／原生角色／REST 升级与变成员工。前台账户优先本品牌顾客，原生 wp-login.php 优先员工；同邮箱两类身份也有明确入口。默认地址属于各自原生用户 ID。密码重置发起、链接和提交都校验品牌；A 改密只撤销 A 的 ID 会话。应用密码对顾客禁用。第三方按邮箱查询应使用 `Identities::find(Brands::id(), $email, true)` 再按 ID 取用户；全局 get_user_by(email) 无品牌含义。

WC 会话 Cookie 名包含品牌；游客 ID 保留 Woo 原生 t_ 前缀且编码品牌；复制 Cookie／Store API Cart-Token 到另一品牌拒绝。订单已有来源不因当前后台改选而重写。新增订单／关联游客订单的顾客归属必须匹配；历史订单金额、地址、状态和行快照保留。

## 选项、集合与邮件

`Brands` 将品牌名称、home、Logo、blog_public、Woo 页面 ID、隐私页／站标、主题展示选项、集合等 option 路由到品牌 option bank；siteurl 保持当前原生请求主机，支持中央后台原生资源加载。一套主题代码／货币／税务／运费／网关／插件配置共用，品牌员工不能改这些全局设置。

集合继续支持分类子树、标签、手选／排除／置顶、50 个未删除集合、每组 200 件及最多 2000 件父商品重建。公共更新后刷新关联品牌；共享分类变化按品牌重算；删除依赖词条停用待检查，不能退回全商品。动态区块、短代码、主题接口与经典 Shop 使用同一份成员关系；Woo Store API 独立集合筛选区块并不自动读取本插件自定义规则。

FluentSMTP 保留一套凭据和精确 From 路由，不修改核心。Woo 通知、直接后台重发、HTML／纯文本模板按订单品牌临时进入作用域，链接、发件人、Reply-To 和页脚一致，结束恢复。未配置品牌禁止发送，无自动补发队列。本地额外 MU 拦截一切实际邮件和支付。

## 显式迁移与边界

`SingleMigration` 只接受当前受限本地私有原生 API 导出，持迁移锁及数据库事务；成功才置 gsm_single_brand_ready，失败回滚并清缓存。激活和访问页面不迁移。保留原站点表／文件及旧审计作为档案；旧 SharedTaxonomies／CategoryMigration／UserMigration 写入入口退休并拒绝。原 .4 迁移若需复原必须用匹配旧源码。

当前源数据只含简单／可变商品，5 笔订单、无税率／退款／下载权限／付款令牌；未将其空样本等同完整支付、订阅、下载或第三方业务迁移证明。未知第三方 SQL／缓存／会员／SSO／订单扩展需专项适配。同库为逻辑隔离；数据库管理员与商城总管理员能访问全商城。

## 0.5.1 原生列表和有效编辑控制

NativeProducts 扩展原生列表单一商品归属（公共／各品牌）及可选公共来源筛选、归属／来源／版本列及行跳转。状态计数通过同一组安全元数据条件计算，不污染原生计数缓存；状态链接保留当前筛选。原生 edit 链接和经典 Woo AJAX 均携带目标品牌，权限仍由服务端归属和能力检查决定。

ProductVersions 在原生保存前恢复继承的公共字段，保存后继续同步；变体覆盖标记在首次持久化前确定，保留 SKU／条码与品牌启用状态。公共商品创建由 Woo CRUD 写品牌元数据，WP 自动草稿阶段才补缺省品牌，避免双重标记。员工不能更换公共来源。

界面仅对已关联品牌版本隐藏共用字段和未启用的差异字段，独立／公共商品仍使用原生控件；库存策略导致的无效控件全局隐藏。变体 AJAX 动态加载后重应用显隐，保留只读规格文本；结构变更及无效批量操作在服务端也拒绝，加载／保存先检查实际商品能力。父级继承设置未保存时提示先更新并收起变体。

继承行移除快速编辑，含继承商品的结果页移除混合批量编辑，保留回收站；公共商品原生编辑不受该限制。中央品牌商品标签及其列表／快捷状态已移除，全部商品管理收敛到原生 Products。

品牌身份及来源对总管理员与员工均以只读文本展示。原生内部 slug 不直接改变门面公开路径，因此本轮收起相关编辑框；空白 General 页签按实际可编辑字段显示，启用价格覆盖后恢复。关联筛选清除入口放在列表控件中，避免被 Woo 通知折叠。

## 原生批量任务（0.5.2）

BulkProducts 扩展原生 bulk actions，Apply 只生成确认清单；确认后由 owner＋随机 token 的私有 transient 保存队列、选择时状态、结果和游标，TTL为最后一次写入后24小时，usermeta指向最近任务。每批最多200个父商品，每个 AJAX 请求处理一件；支持暂停／继续／仅重试失败项。原生 bulk-posts nonce、确认／执行 nonce、逐次身份与逐对象品牌权限校验均保留。任务 token 不是授权，不记录于日志。

每任务使用 MySQL GET_LOCK 防止重复推进；公共商品／品牌组合另有锁，ProductVersions::ensure_edition 包含回收站查重，所有公共面板新建关联也经过同一锁。已有关联不写入；生成父／变体使用 Woo CRUD、空覆盖和草稿，媒体引用共用。_gsm_build_state 标记未完整建立版本，集合同步暂时跳过；捕获写失败仅清理本次新建记录，硬中断留下草稿由人工检查并 Update 完成。

批量状态只处理品牌销售商品，逐件 within 校验 edit_post 和 publish_products。选择后状态改变跳过，不覆盖并发状态修改；已经达标也跳过，不触发无效保存。逐件审计只记动作、编号、原因类别和状态；整批部分成功不回滚，页面关闭不继续后台执行。没有新业务表、迁移或旧发布器恢复。

## 统一继承模型（0.5.3）

所有品牌销售父商品必须关联同类型公共父商品，变体必须映射到该公共父商品下的变体。不再支持独立品牌商品。新建原生商品默认公共，专用create_posts能力仅授予平台管理员；品牌员工继续编辑其已授权版本和差异，不能直接新增公共或品牌商品。

ProductVersions在Woo写入前验证关联、类型及固定品牌／来源，新增销售版本只能经过公共商品物化器。原生editpost在admin_init先校验nonce、完整表单、归属、来源及类型，早于WordPress写标题／内容，避免非法表单部分保存；对象保存时仍重复验证。直接WC CRUD／REST不能清空或改关联。受信内部生成使用unscoped，保留队列并发锁。无登录用户的Woo cron仅允许已存在、关联有效的记录更新，公共同步只传播现有版本，不以cron身份新建品牌关联。

Products的gsm_brand=0现在明确表示公共目录；正数表示指定品牌版本，不再有列表“全部品牌”或gsm_products类型筛选。只有原生Products列表的无显式scope请求忽略全局品牌Cookie，其他页面的既有品牌作用域保持；员工仅授权品牌，不开放公共目录。品牌特有商品仍是公共档案，只给特定品牌生成销售版本即可。

本地已保存的品牌商品全部有关联，无需搬迁或重建ID；仅两条未保存的auto-draft归属从旧品牌改成公共，私有原元数据备份留存，状态和内容不变。没有自动生产转换或独立商品批量迁移器。

## 中央商品页面退休（0.5.4）

Admin只保留五个标签。删除ProductAdmin及仅供旧中央页面使用的Catalog服务和启动加载；旧edition_save／edition_status在原权限及nonce校验后返回410，不再处理或暂存商品表单。原生ProductVersions／NativeProducts／BulkProducts及Woo状态保存不受影响。

旧tab=products的GET在admin_init、输出前进行平台权限与品牌／商品一致性验证，再按已登录后台的相对路径安全跳到原生列表或编辑页，避免品牌home域名导致wp_safe_redirect回落。无品牌参数进入公共目录，无效／不匹配请求404。概览商品链接直达原生品牌列表。媒体／富文本和共享样式继续供集合编辑使用，集合规则及数据没有变更。

## 分类视图与集合层级（0.5.5）

TaxonomyAdmin只在原生product_cat列表的all／count查询应用共享或指定品牌条件；共享以集合term ID排除，品牌以collection标记＋品牌元数据约束。无显式范围默认共享，不读上次Cookie。列表、计数和分页同范围，原生共享parent选择器不含集合；品牌集合编辑与回收统一引导CollectionAdmin，原生快捷写入受保护。共享Woo拖动排序的遍历排除集合，不改品牌集合顺序。

集合层级直接使用term_taxonomy.parent，不另存第二份父级。Collections验证parent为同品牌规则中的未删除集合且不成环，新增集合在重建前写入_gsm_brand_id。各集合成员仍独立物化，不以父子关系继承规则或汇总商品；原生归档增加include_children=false约束，与区块／Shop一致。BrandScope计数只对普通分类累加后代商品。Collections::tree按同级order／名称生成显示树，过滤后父级不可见则子级作为显示顶层，不改存储关系。前台导航嵌套，区块与Shop选项缩进，主题数据返回parent。

全局product_cat_children缓存的get=all＋fields=id=>parent内部查询不加品牌过滤，避免某品牌保存层级后破坏其他品牌树。没有业务迁移、term ID或slug重建；parent改变会自然改变原生层级归档URL，不新增旧地址重定向服务。

## 原生独立页面（0.5.6）

NativePages扩展page原生列表和写入边界。所有页面属于一个真实品牌，不存在品牌0公共页面模板。平台管理员列表默认主店，正数gsm_brand选店；编辑器上下文由现有页面的品牌确定，新建链接保留列表选择。WP_Query／REST页面选择／状态与作者计数同范围。NativePages在wp_insert_post_empty_content和rest_pre_insert_page写入前校验固定归属、角色及父级；新记录在wp_after_insert_post写唯一品牌标记，现有标记不能通过直接元数据增删改绕过。初次实际保存修正auto-draft公开路径标记。正文、标题、发布和修订继续原生，不做继承或同步。

Brands的后台rest_url使用当前注册请求主机的siteurl，避免切换品牌后的home把Gutenberg请求带到另一个登录域；X-GSM-Brand及原nonce仍负责业务范围授权。受控平台管理员只对平台管理员自己的品牌用户查询可见，员工边界保持。品牌页面草稿预览留在已登录主机，页面级nonce＋平台管理员／明确品牌Administrator授权共同选择预览品牌；其他前台访问仍按Host。404的WordPress地址猜测不能跳到另一注册品牌。

旧单店Site_Experience政策安装器在多品牌启用时卸载其init回调，防止按全局slug查到并覆盖独立页面。隐私／条款 option bank、现有页面和页脚路径沿用，不新建用途映射、不自动改政策正文，也无数据迁移。

## 原生菜单按店铺管理（0.5.7，本地）

外观 → 菜单新增“菜单所属店铺”切换，总管理员直接进入默认主店。每店可建立多个菜单，Primary／Footer 位置及自动添加页面设置独立。左侧商品、页面、文章只显示本站已发布资料；商品只使用品牌销售版本，排除公共档案。Product categories 包含共享分类与本站启用且未回收的集合，标签仍共用。自定义链接限本站域名、以 `/` 开头的相对路径或 `#` 锚点，不提供外部链接。

服务器在原生表单或 AJAX 写入前校验店铺签名、菜单／菜单项归属、资源与父项；阻止跨店资源、其他菜单的菜单项、循环层级和跨店位置绑定，非法混合保存不会先改菜单名。前台按域名读取位置，并过滤历史无效／跨店条目；不自动删除既有资料。页面自动添加在归属写入后按页面所属店铺执行。

品牌 Administrator 使用“店铺菜单”入口，只授予菜单页面和受控 AJAX 所需能力；不获得主题、插件或全局设置权限，Shop Manager 权限保持。经典原生编辑器是当前受支持入口，Customizer 菜单组件及菜单相关 REST 编辑入口关闭，第三方菜单编辑器需另行适配。WordPress 菜单名称仍全站唯一，建议使用“店铺名＋菜单用途”。

主题 Footer menu 已实际接通 Customer care 栏；有绑定时显示该店页脚菜单，未绑定时保持现有政策／账户链接。新店不复制主店菜单位置或自动添加设置。无菜单内容复制／继承，无业务菜单迁移；只在本地验收，尚未发布。

实现位于 `NativeMenus`，保留原生 nav_menu term、nav_menu_item post 与 `_gsm_brand_id`。nav_menu 查询始终限定当前品牌，显式菜单 ID 同样受限；`theme_mod_nav_menu_locations` 拒绝读取外店绑定，`pre_update_option` 在品牌 bank 路由前校验位置及 auto_add。原生 `nav-menu-data` JSON 在 load 阶段展开并统一校验后才交核心处理，避免绕过或部分保存。菜单名不做命名空间转换。

网页修改只能经过本插件支持的原生入口；受信 PHP／数据库管理员仍具全商城能力，不宣称限制直接 SQL 或未知插件。商品分类全局 `id=>parent` 层级缓存仍不按品牌裁切。页面自动添加从 transition_post_status 移至 wp_after_insert_post，晚于 NativePages／BrandScope 的归属标记，按 owner 临时进入作用域并恢复。

## 2026-11-05 本地业务重置与 Chanel 公共目录

用户明确授权清空业务数据，保留七品牌、后台管理员及环境配置，并选择“只导入公共档案，暂不生成销售版本”。本轮已完成一次性本地重置：原5笔HPOS订单、289条商品／变体、155条其他内容记录、7个顾客、84个业务词条及旧子站档案中的业务行已清理。保留2个后台员工／管理员账户、原密码与身份、七品牌注册及联系／邮件／集成配置；保留主题配置记录和原表结构，不重建数据库。页面／菜单／集合／Logo等已删除资源绑定归零；系统默认分类重建，不自动恢复政策或Woo功能页面。

用户 CSV `wc-product-export-27-8-2026-1787800592981-working.csv` 的72件简单商品已通过Woo原生CSV导入器新建为公共档案，保留SKU、价格、长短描述、349项本地属性、分类层级及补充元数据。72张图片全部从本机香水素材目录导入并校验原图SHA256；无原网站连接，源CSV哈希不变。来源ID只作为元数据记录，不复用其他安装的原生ID；没有生成任何品牌销售版本，七店前台均暂不展示商品。

私有成套备份与运行清单：`/private/tmp/group-shop-manager-qa/imports/20261105-chanel-6bd9d69e/`（backup/database.sql、uploads.tgz、source.tgz、qa.env及verified.json）。361张表、旧上传文件、源码和环境文件已备份并校验，包含敏感资料，不提交或公开。当前有效数据基线为72公共／0品牌／72图片／0顾客／0订单／0页面／0菜单／0集合；先前“718项原迁移资料保留”的验收仅为重置前历史，不得继续作为当前数据断言，旧私有fixture中的商品／顾客编号也不可直接复用。

1,662项逐字段／图片字节／归属／配置／旧档案清理核对通过，另有24项七域名与实际媒体HTTP核对，以及原生后台72件公共商品和缩略图浏览器检查。报告：`qa/group-shop-manager/import-public-chanel-20261105/REPORT.md`。软件版本仍0.5.7；仅本地数据维护，未发布或连接生产。

本地重置改变的是数据基线，不改变公共档案＋品牌销售版本模型。旧子站归档表结构仍存在，业务行已按本次用户授权清空；gsm_single_brand_ready／身份ready／品牌UUID／单调序列继续保留，旧商品映射与历史迁移数据报告已从活动选项移除。公共商品继续不直接销售。

## 0.5.8 商品展示继承与店铺独立加购（本地）

保留 Trovesia add-ons，按店铺独立配置，不继承公共商品规则。公共商品的 add-ons 面板仅提示进入品牌版本配置；品牌版本的选择器只列同店其他简单商品及具体变体。保存前验证完整表单、原生编辑授权和同店关系，拒绝公共／跨店／自身关联；最多20条规则。原生元数据写入也校验归属，现有规则不自动复制或删除。

加购时核对规则与商品，子项不继续触发嵌套加购。购物车重算检查真实父行、规则、子商品及变体、所属店铺与可购买状态；规则删除、换绑或商品不可用时移除失效加购并提示重选，不再使用旧价格快照兜底。跟随价格／固定价／折扣继续可用，数量跟随主项；加购价不污染同一商品的普通购物车行。独立原生订单行、父项移除联动及普通迷你购物车数量按钮保持。

Product presentation 默认动态继承公共商品，涵盖Eyebrow、Key benefits、退货说明、配送说明、Order activity文案及原有配送天数元数据；公共修改即时生效，不向品牌批量复制文案。在该面板勾选“单独设置本店展示信息”后可独立编辑，取消勾选并保存恢复当前公共值。留空仍使用Trovesia原有默认文案；自动订单数的查询与缓存均按当前店铺计算，不继承另一店的数量。继承模式字段不可编辑，服务端忽略伪造字段并阻止直接元数据覆盖；覆盖选择沿用 `_gsm_overrides` 的presentation项。

Group Shop Manager 0.5.8、Trovesia Plugin 3.8.4；单店插件保留原有基本行为，多店规则由 ProductExperience 适配层施加。原生编辑和经典加购为当前支持入口，Store API购物车读取／变更已有项经过相同重算；不宣称新增区块加购选择器或第三方编辑器兼容。

本轮开始只读盘点为72公共＋72品牌版本，已有展示及加购元数据均为0；没有自动改写这些业务商品、建立版本或设置营销规则。29项运行、5项订单统计和23项真实HTTP检查，以及浏览器实际覆盖保存／恢复继承通过；合成资料清理与原商品／元数据／品牌设置快照核对见 `qa/group-shop-manager/v058-local/LOCAL-v058.md`。仅本地，未连接或发布生产。

ProductExperience 不引入新表。展示字段的权威为公共字段／本地覆盖标记：原生 get_post_meta 与Woo商品视图get_meta按归属解析来源，保留原始本地元数据以便显式覆盖，不将其误当继承值。原生完整表单在editpost写标题前验证；Trovesia保存回调只在有效覆盖模式写本地字段。公共规则不进入品牌物化器的meta_data，继承展示信息不会顺带复制add-on商品ID或价格。


## 0.5.9 公开资源与SEO边界

SeoIsolation在parse_request检查显式商品／页面ID和私有分类归档，非法请求写error=404；template_redirect再次检查归档对象。BrandScope::posts以AND嵌套已有meta_query，不能让OR条件旁路品牌；公开slug查找带post_type，避免页面／商品同名串类型。redirect_canonical停止404猜测和跨注册品牌跳转，old_slug_redirect_post_id验证真实归属后才保留本店旧slug301。

REST在rest_request_before_callbacks拒绝外店／公共商品、父子不匹配变体、页面／文章、评论及私有集合ID。Woo 11 ProductsBySlug使用get_page_by_path／SQL而非受限WP_Query，额外按品牌与公开／内部slug定位销售记录；成功响应必须在rest_dispatch_request转交原生ID处理器，before_callbacks返回非错误响应不会中止原回调。分类响应在rest_request_after_callbacks按当前品牌重算count／review_count，保证嵌入和内部rest_do_request也一致；覆盖产品分类、标签、制造商品牌及原生属性term接口。共享词条名称／slug／描述／封面依旧共享。

Woo FilterData可在WP_Query前命中持久缓存，分类／属性计数经woocommerce_pre_product_filter_data给query_vars加入品牌meta_query与内部标记，再调用相同Store API查询器，避免跨域同形查询复用另一店缓存。价格／库存／评分Store API聚合从受限WP_Query SQL计算，原生Query生成的统计保留品牌meta_query。受信内部unscoped可绕过，标记不是对外授权参数。

分类Sitemap通过品牌已发布商品关系求可用词条及共享祖先，参与原生列表和分页数量查询；不以全局term count决定店铺目录。用户Sitemap provider关闭且其直接入口404。商品／页面／文章Sitemap、Feed和搜索沿用原生品牌查询，相关／交叉销售ID在公开读取时再过滤。菜单沿用NativeMenus，非法显式菜单可能由WordPress回退到另一个本店菜单，但不得渲染外店菜单。

the_content／the_excerpt／woocommerce_short_description渲染时使用WP_HTML_Tag_Processor移除指向其他注册店铺业务页的a.href／iframe.src，pre_oembed_result阻止外店嵌入；不改数据库正文，不猜测替换为本站路径。wp-content链接、图片、CSS、JS继续共享。第三方任意PHP／SQL读取不自动授权；新增SEO插件、路由或升级Woo内部FilterData接口必须复跑专门验收。
