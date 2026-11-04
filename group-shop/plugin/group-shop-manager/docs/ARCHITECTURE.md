# 架构与不变量

## 选择：原生 Multisite，而非独立安装之间复制用户

group-shop 是网络主店。WordPress 共用 `wp_users` / `wp_usermeta`，每个博客拥有自己的商品、评论、分类、选项、WooCommerce 订单表。店铺编号使用 WordPress blog_id，另存 UUID；域名和品牌可改变，编号不能重用。

原有独立 trovesia-shop 不属于此网络。没有远程连接器、密码同步服务或跨域 SSO。0.4.0 顾客按店映射到不同原生 WP 用户 ID，员工为 scope=0 的独立共享身份。每个 ID 的原生密码与会话独立；修改密码仅撤销该 ID 会话。

## 数据归属

| 数据 | 存储／归属 | 变更原则 |
|---|---|---|
| 顾客邮箱、密码、姓名、默认账单／收货地址 | 每店一个原生用户 ID（共享 wp_users 表） | 同邮箱可跨店重复；修改不跨 ID 同步，订单快照不变 |
| 身份／可见用户名／邮箱唯一范围 | 网络表 `{base_prefix}gsm_identities` | user_id 主键；network_id + store_id + login_key/email_key 分别唯一；员工 store_id=0 |
| 员工密码和角色 | 独立原生用户；每店原生 capabilities | 只有网络超级管理员分配 admin／shop_manager；无自动加店 |
| 首次注册来源 | usermeta `_gsm_first_store` | 只写一次；迁移顾客按所属店设置，缺失不猜成主店 |
| 分店首次登录／注册时间 | usermeta `_gsm_store_{id}_joined_at` | 只写一次，不覆盖首次来源 |
| 品牌、邮件、永久 UUID | 每站 option `gsm_store_settings` | UUID 保存不变；SMTP 凭据不放这里 |
| 产品／变体／排序／评论／发布状态 | 原生各博客表 | 按店独立；必须切换店铺后操作 |
| 产品分类／标签 | 主站原生 term 为唯一权威，各店为原生投影 | 主站原生后台统一编辑，主商品归属同步到映射版本；集合按店独立 |
| 订单／订单行 | 原生 WooCommerce CRUD，兼容 HPOS | 原始店铺是唯一数据来源；通过所属店原生后台管理，无中央汇总 |
| 历史主商品到版本映射 | 网络表 `{base_prefix}gsm_catalog` | 保留旧记录供分类同步；0.3.7 不再通过商品发布创建／更新映射 |
| 操作日志 | 网络表 `{base_prefix}gsm_audit` | 仅记录编号、动作、字段、计数与状态等 |

订单写入 `_gsm_store_id`、`_gsm_store_uuid`、`_gsm_store_name`、`_gsm_origin_url`。已存在的归属不覆盖；客户前台不得访问归属不匹配的订单。店名修改不改历史快照。

## 类职责

- `Schema`：幂等建表、版本号、最小操作日志、主店初始设置。
- `Stores`：权限、作用域、店铺列表、草稿建店、品牌和邮件设置、缓存清理。
- `Catalog`：仅保留本店商品编辑、状态切换及变体保存校验；跨店快照、复制、媒体导入、字段覆盖和映射写入 API 已在 0.3.7 移除。
- `Availability`：全网络不管理库存、始终有货的运行策略及现有商品/变体的 CRUD 迁移。
- `Collections` / `CollectionAdmin`：本店持久选品规则、列表／独立编辑页／回收站、授权商品搜索，物化为原生关系。
- `CollectionDisplay`：同一集合的原生动态区块、短代码、主题读取接口与经典 Shop 主查询筛选。
- `ReviewAdmin`：原生 WooCommerce Reviews 列表的只读所属店铺列，评论及回复按当前 blog_id 归属；不注册写操作或跨店查询。
- `TaxonomyAdmin`：原生分类／标签列表和编辑页的只读类型、当前店归属、集合链接与作用域提示，不改变 CRUD／权限。
- `SharedTaxonomies`：主站原生词表权威、各店只读投影、既有独有词条汇集、主商品归属同步、权限与失败重试。
- `ProductAdmin`：本店商品主图列表、独立上下架、原生富文本／媒体、每页 20 个变体编辑。
- `CategoryMigration`：授权升级时转换旧集合编号，清除退休模板来源/覆盖元数据，不删除原生业务表与分类。
- `Identities`：作用域唯一索引、原生用户创建／邮箱修改、MySQL 注册锁、类型／归属与登录别名；真实邮箱和密码保留原生。
- `Customers`：按店身份认证、Cookie／重置范围、角色和会员关系保护、注册／REST 适配、员工购物限制与提示。
- `UserAdmin`：原生网络／各店用户列、类型只读、顾客角色锁定、原生员工创建说明与网络管理员按店授权。
- `UserMigration`：显式预检、检查点续跑及旧顾客拆分，不自动执行。
- `IdentityPrivacy`：将原生按邮箱导出／擦除回调改为本店顾客解析，保留本店订单／下载／令牌范围。
- `Orders`：来源快照、前台归属保护、已有 HPOS 列，以及按目标店存储模式生成原生订单后台入口；不再提供聚合查询或中央写操作。
- `Mail`：发件身份、Reply-To、店铺头、页脚及禁止未配置店铺发信。
- `Admin`：主店菜单与表单，只允许网络管理员写入，所有写操作经过 nonce 和能力校验。

## 跨店访问规则

所有跨店访问都通过 `Stores::within()`，先验证同网络且未归档/删除，再 `switch_to_blog`，finally 恢复。不得把仅有数字产品或订单 ID 当成跨店唯一标识。WooCommerce 商品读写使用 CRUD；不直接 SQL 改库存或商品 lookup。分类使用 WordPress 原生 term API。

`Stores::can_manage()` 在多站点要求网络超级管理员；顾客登录只接受其唯一所属店，登录绝不自动增加成员关系；员工按显式原生角色授权登录。

## 身份与原生用户（0.4.0，仅本地）

原生 users/usermeta 仍是资料、密码与会话权威，新增 gsm_identities 不复制密码、真实邮件或地址。顾客内部 user_login 为 gsmc_{store}_{UUID}，员工为 gsms_{UUID}；迁移保留原用户 ID 的原有用户名。login_name 为用户可见名称，login_key/email_key 以 trim+小写归一，按网络／店铺分别唯一；员工 scope=0 单独唯一。禁止公开注册保留前缀或邮箱形式用户名，避免用户名与邮箱查找歧义。不能以内部用户名绕过登录入口。

原生 email_exists/username_exists 改成当前作用域查询；wp_pre_insert_user_data 最终校验、按登录名与邮箱排序获得 MySQL GET_LOCK，然后建立原生用户与唯一索引；重复／竞态失败拒绝或清除新建用户。修改邮箱同时校验所属范围并更新索引。锁在注册／更新完成和 shutdown 释放。数据库异常仍非核心 users＋索引的完整事务，迁移须备份；不声称任意直写数据库安全。

前台优先本店 customer，原生 wp-login 后台优先 staff；认证保留 WP 密码哈希／rehash／认证用户过滤及垃圾用户检查，最终再校验范围。determine_current_user 在原生 Cookie 验证后拒绝异店顾客和无授权员工。相同邮箱另店不自动加入；顾客应用密码禁用，已授权员工的原生应用密码仍遵循店铺访问范围。第三方绕过原生认证链的 SSO 需单独验证。

顾客类型和 home 固定，角色严格 customer；can_add_user_to_blog、metadata、map_meta_cap、user_has_cap 及 site_admins 更新共同阻止跨店成员、升级及授予超级管理员。用户编辑服务器校验覆盖 DOM 禁用控件，REST 用户角色更新及数字 ID 跨店访问也拒绝。员工分店角色只允许网络超级管理员修改，不可自我加店；每店只允许 Administrator／Shop Manager。员工下单拦截用于保持业务身份分离。

密码重置发起先用本店／员工入口解析别名或真实邮箱，再交原生重置；key+id/login 链接、最终确认及 allow_password_reset 校验归属。顾客改密只撤销该 ID 会话；员工改密影响共享员工身份。发送品牌使用原始当前店；邮件正文替换内部用户名时避开 URL 参数，不能破坏原生带 canonical login 的令牌链接。

网络／店铺原生列表显示类型／范围与可见用户名，网络列表另有首次来源和原生 Sites。UserAdmin 的原生资料页只向网络超级管理员显示员工逐店授权控件，nonce＋所有店铺字段＋末尾完整标记校验后保存并撤销旧会话。顾客普通编辑永不能转员工；单独新建员工。无重复客户列表。

原生 WordPress 用户资料导出与 Woo 顾客／订单／下载／令牌导出、擦除使用 IdentityPrivacy 的本店解析；订单擦除尊重原配置与原生操作。令牌 ID 窄查询后用原生 CRUD，包含已停用网关及分页，避免 Woo 默认查询漏记录。订单创建保存拒绝异店 customer ID，拒绝日志只记编号，不让 Woo 原生日志写完整订单对象。新版邮箱确认事件只为本店顾客执行原生游客订单绑定；网络编辑异店顾客或员工时控件禁用、服务器移除确认字段，旧报表 link_orders 也校验本店身份。第三方按邮箱全局 get_user_by/email 查询不会自动变成安全本店查询，须用 Identities::find(get_current_blog_id(), $email, true) 再按 ID 读取；后台编辑异店目标使用 Identities::scoped。其他插件的个人资料导出器也需独立审核。

这是 Multisite 同库逻辑隔离，数据库管理员／网络超级管理员能管理全网。无物理分库、自动 SSO、多网络用户兼容承诺或共享顾客资料。安装未完成 gsm_identity_ready 时顾客登录／注册关闭；不会自动迁移。

## 旧用户迁移（0.4.0）

UserMigration::plan 先检查未分类用户。纯 customer/subscriber 以有效首次来源（否则首个所属店）为保留原 ID 的 home，其余所属店分配新 ID；管理员／Shop Manager 保留员工 ID 与管理店权限，其 customer-only 店拆成独立顾客；超级管理员保持原身份。未知角色、停用归属或跨网络成员先阻止，不猜测转换。

显式 run 持有网络迁移锁，禁止发信，保存 source 检查点，全部完成才置 ready。保留原 ID 密码哈希，新增 ID 只一次复制哈希和默认地址起点，后续绝不同步。原生 CRUD 重绑所属店订单顾客、产品评论作者、持久购物车、下载权限和既有付款令牌；历史金额／状态／地址快照不修改。撤销旧共享顾客会话；员工管理 ID／原授权保留。按来源完成后记录 done，可续跑；多站迁移不是一个数据库事务，回滚须恢复匹配完整数据库与旧源码。无主动收款／退款动作。

## 原生商品评论（0.3.5，仅本地）

Admin 移除 reviews 标签、自建查询／分页／审核表单及 review_status 写分支；旧标签由白名单回退概览，旧写请求在原能力／nonce 检查后以未知操作拒绝。没有新增审核入口、权限或查询改写。

ReviewAdmin 使用 WooCommerce 的 woocommerce_product_reviews_table_columns 和 woocommerce_product_reviews_table_column_gsm_store，给原生产品 Reviews 表格增加“所属店铺”。回调接收 WP_Comment，验证目标在当前上下文为 product，再输出转义后的当前店名称及 blog_id。原生回复也属于产品，不限定 comment_type=review，以免回复行缺失归属。归属依赖当前原生 comments 表，不按裸评论／产品编号跨店查源，不增加 commentmeta，不复制评论或建立共享评论表。

普通 edit-comments.php 不注册新列。现有原生角色、审核／编辑／回复／筛选／批量操作和第三方精选／媒体列保留，评分与购买验证仍按店计算；此扩展不聚合全网，也不提供跨域 SSO。历史审计保留，退休的中央 review_moderated 事件不再由原生操作生成。

## 原生订单入口（0.3.4，仅本地）

Admin 移除 orders 标签、自建列表／详情和 order_note／order_status 分支；旧标签由现有白名单回退概览，旧写请求在原有能力／nonce 检查后按未知操作拒绝。Orders 删除 list／change_status／next_statuses，概览改用 Orders::admin_url。该方法要求中央管理权限，在 Stores::within 目标店中通过 OrderUtil::custom_orders_table_usage_is_enabled 选择 admin.php?page=wc-orders 或 edit.php?post_type=shop_order，并 finally 恢复上下文；不得按主站模式决定全部分店的 URL。

订单创建来源 stamp、belongs_here、顾客 view_order／pay_for_order／order_again 校验、HPOS 来源列及邮件原始店上下文保留。没有订单复制／迁移、删除、数据库或新增权限变化。原生后台按本店查询和原生角色处理，不支持全网合并列表，不新增自动 SSO。中央有限状态白名单及其审计事件不再产生，历史审计与订单备注保留；原生状态／备注操作沿用 WooCommerce 权限、通知及网关配置。

## 主商品发布退休（0.3.7，仅本地）

Admin 移除 catalog 标签、渲染函数、catalog_publish 分支；旧标签按白名单回到概览，旧 POST 经过原有权限／nonce 检查后以未知操作拒绝。Catalog 移除 FIELDS、mapping、attribute_snapshot、variation_attributes、snapshot、import_media、publish、publish_locked，以及发布锁、跨店商品／变体／属性／媒体复制、字段覆盖、映射写入和 catalog_published 审计产生逻辑。没有替代入口或隐藏发布 API。

Catalog 类保留原 set_status／save_edition／prepare_edition，继续使用所属店 Woo CRUD、Stores::within finally、先验证完整批次、价格／附件／父子归属和原生变体同步。本次不改这些方法行为。店铺新增商品和结构调整走本店原生产品后台。

不删除或改写已有商品、变体、图片文件／附件、_gsm_master_*、_gsm_source_store／_gsm_source_attachment 来源元数据、历史映射和审计。Schema 的 gsm_catalog 保留兼容结构，SharedTaxonomies 仍查询原映射同步统一分类／标签归属，并维持关联商品既有原生分类面板控制；不新增映射，不将内容或价格复制回来。此保留是既有分类决策的兼容行为，不是仍保留发布功能。停用／卸载保留业务数据约定不变，无清理迁移。

旧商品／集合／分类套件依赖 Catalog::publish 来生成 fixtures，不能直接用于当前版本，既有 localhost 保护也不得移除。本轮改用退休 API 检查、本店临时 CRUD fixture 和只读七店摘要；历史发布测试只代表当时功能。

## 统一分类与本店集合（0.3.1，仅本地）

此版本按最新用户决定覆盖 0.3.0 的独立词表方案。主站原生 product_cat/product_tag 是唯一权威，UI 只有主站原生编辑页。Multisite 中每店的 term ID 和商品关系必须本地化，不能共享表前缀或跨店复用数字 ID，因此保留受控原生投影；这些不是可独立编辑的数据源。没有新的分类业务表，没有模板／覆盖字段或第二套分类编辑器。

SharedTaxonomies 在网络管理员普通 admin_init（CategoryMigration 之后）检查每店 gsm_shared_taxonomy_revision。首次先汇集各店独有的普通分类／标签，按原生 slug 复用主站已有项，冲突时主站内容为准；默认分类直接归一到主站默认分类。分店已有 term ID 尽量保留，_gsm_shared_source 只记录对应主站 ID。既有集合及其规则不汇入主站。

saved_term/delete_term、thumbnail_id/order、默认分类修改增加网络修订号，请求 shutdown 合并处理一次；使用网络 advisory lock 串行化投影。名称、slug、介绍、父级、图片和排序写入分店原生 term API；图片复制到本店媒体并以 _gsm_shared_media 来源键复用，不跨店复用附件 ID。各店 terms 的其他元数据保留。创建新店在初始化结束后接入；未活动店不越过 Stores::within 校验，重新启用后补做。失效项通过本店原生删除 API 移除；与店铺集合 slug 冲突时中断并显示错误，不覆盖集合。

主商品归属变更在 set_object_terms/deleted_term_relationships 收集，按 gsm_catalog 映射同步全部已关联版本，商品写入使用 WooCommerce CRUD（包括其商品实例缓存失效）。0.3.7 不再有首次／再次发布入口；历史映射的归属同步继续独立生效。分店关联版本的原生分类面板移除，伪造的关系变更会恢复主商品归属；仅分店独立新建、无映射的商品可在原生编辑器选择共享词条。

网络管理员才可中央写入；分店 manage/edit/delete_product_terms 返回 do_not_allow，原生分类页返回主站；pre_insert_term 阻止分店建立新词条。受控集合保存和同步过程有明确内部作用域，允许生成本店集合分类。第三方直写数据库或绕开控制器的低层 term API 不属于受支持编辑途径，需网络管理员显式 reconcile(true) 修复投影。

集合仍为每店 gsm_collections + _gsm_collection 派生 product_cat；taxonomy_scope=local，规则 ID 是共享词条在本店的 ID，不得用源 ID 代替。OR 分类子树、标签任一／全部、选入／排除／置顶、归档与导航保持。批量投影时延迟集合重算，再按店重建；删除规则依赖项会停用并 needs_review，避免空条件扩选。集合仍按店独立，不把集合变成共享分类。

同步只在全部投影及商品关系成功后标记店铺修订号。异常显示 gsm_shared_taxonomy_error，保留待同步版本，下次网络管理员普通后台请求重试；不宣称跨店事务或失败自动回滚。迁移会合并词表并改变原分店差异分类及已映射商品归属，完整回滚需要匹配数据库、代码及 uploads 备份。

0.3.0 的 CategoryMigration 仍先将旧集合来源 ID 转为本地，再删除 _gsm_template_id/_gsm_template_store/_gsm_overrides；没有 DROP TABLE，gsm_catalog/gsm_audit 不变。旧 v030 分类测试与报告只代表历史独立分类语义，当前使用 shared-taxonomies.php 验收。

## 原生词表的归属提示（0.3.3，仅本地）

TaxonomyAdmin 使用 manage_edit-{taxonomy}_columns / manage_{taxonomy}_custom_column，为 product_cat/product_tag 原生列表补类型和所属范围。只有 product_cat 且带 _gsm_collection 的项标记集合，状态读取当前店 gsm_collections，归属使用当前 blog_id、名称及 home URL；不能按裸 term ID 跨店推断。其他分类／标签显示全站共享。

原生编辑页通过 {taxonomy}_edit_form_fields 输出相同只读字段；网络管理员可经行操作／店名进入本店集合编辑，回收站项进入回收站，缺少规则的标记项退回本店集合列表。原生页面提示它仅显示当前站点记录，其他店集合经中央入口切换，不聚合跨店原生 term 查询。

不新增字段、业务表、迁移、写处理器、权限或原生删除拦截。CSS 只在两类原生 taxonomy screen 加载；桌面宽度不足时列表排在原生新增表单之前，保留原生搜索、排序、分页和响应式表格。

## 商品编辑与原生控件（0.3.1）

列表主图和价格从所属店原生商品读取。快捷动作只允许 draft/publish，校验 expected_status 防止旧页面重复点击，并处理原 future 发布时间，始终只操作所选店。所有写请求必须网络管理员和 gsm_action nonce。

编辑使用 wp_editor 长／短描述，wp_kses_post 保存；wp.media 用固定目标店授权。本店主图／相册最多 100 张，变体一张。媒体查询、上传、附件编辑及 send-attachment-to-editor/send-link-to-editor 都在目标店原生 handler 运行；原生 wp.media.post 是独立别名，必须同 wp.media.ajax 一起加入目标店上下文，防止编号相同引用到主站附件。

变体每页 20 个，以父商品为边界，编辑价格、publish/private 启用状态、图片、描述；当前页批量填价不改其他页。先 prepare 所有行、检查父级及附件、价格，再经 Woo CRUD 保存并 sync 父商品价格。末尾 gsm_product_complete 防 PHP 输入截断。验证失败不写任何行，当前管理员最多 200 KB 输入保留 120 秒、只在同店同商品取回，不记入审计。数据库故障或第三方保存钩子异常仍可能部分落库，未宣称数据库事务。

## 商品集合与展示入口（0.3.2，仅本地）

集合仍使用本店 option gsm_collections 及 _gsm_collection 派生 product_cat，不新增业务表、不共享集合编号。标题／描述沿用原生 term，封面使用本店 thumbnail_id，导航／集合排序沿用 order。新建 slug 为系统生成的 gsm-collection-{uuid}、parent=0，客户端 slug／parent 不参与写入；已有 slug／parent 保持，避免破坏既有归档链接及规则。

编辑器通过 collection=new／数字编号进入独立页面，默认集合入口只显示列表。增加 shop_filter（旧记录缺省 false），只有启用且勾选者进入经典 Shop 筛选。新建 UI 默认勾选，页面模块不要求这个开关。每店最多 50 个未删除集合（含停用），重建仍最多 2000 父商品，手选每组 200。商品选择 AJAX 在整个当前店商品库搜索标题或精确编号，每次返回 25 条与本店缩略图／状态；必须网络管理员、按店 nonce，Stores::within 恢复上下文，不注册匿名操作。

保存通过 gsm_action 能力和 nonce，表单末尾 gsm_collection_complete 防 PHP 截断。错误私有保留本管理员同店同集合字段 120 秒、最多 200 KB，不写业务审计。新规则保留分类任一子树、标签任一／全部、两组 AND、手动选入、排除优先、置顶等语义。派生成员通过 Woo CRUD 写分类 ID，以同步商品实例缓存；不会改变商品内容、价格或发布状态。

删除为可恢复软删除：deleted_at 与 trash_state 记录此前启用／导航，规则保留，enabled/navigation=false 后重建移除派生成员。集合图片、原生 term、产品、共享词条均不删除；归档用 template_redirect 返回 404，数据／区块／Shop 入口不暴露失效集合。恢复先检查数量和重建限制，重建原规则；needs_review 时保持停用。没有永久清空入口。底层原生 term 与回收站规则继续持久保存，停用／卸载按已有业务保留约定处理。

CollectionDisplay::data 只读当前店、启用、未删除、taxonomy_scope=local 且有集合标记的数据；失效或跨店数字 ID 返回 null。query_args 仅允许调用者调整 posts_per_page（1–48）／paged，固定 product、publish、Woo 可见性与严格本店集合 term 条件，不把缺失集合变成无限制查询。商品排序来自集合，手动置顶只保留公开实际成员。

动态区块 group-shop/collection 在原生页面编辑器从当前店已启用集合下拉选择；用原生 server-side-render 预览，控制数量／列数／分页和标题／描述／图片。短代码 [group_shop_collection] 复用同一查询和主题 Woo 商品卡片，分页键 gsm_page_{id}；finally 恢复 WordPress post、Woo loop 和 product 上下文，不污染同页其他模块。主题可使用 group_shop_collection()/group_shop_collection_query_args()，调用前应检查 function_exists。

经典 Shop 在 woocommerce_product_query 中把 gsm_collection 条件 AND 到原有属性／价格等约束；不覆盖原有 tax_query。用户未明确排序时应用集合顺序，明确排序时尊重顾客选择。筛选表单只保留原生属性、价格、评分、排序、搜索、语言参数，重置分页；空结果仍显示表单。非法、停用、删除或未配置 Shop 的编号将主查询限制为 [0]。导航 [group_shop_collections] 保持已有兼容。

此主查询适配不自动改造 Woo 原生 Store API 独立查询的 Product Collection／Filter 区块或第三方 AJAX 列表；需要主题适配、原生 category 条件，或使用本插件区块／读取接口。不会自动替换已有主题首页写死模块。集合描述遵循原生 term HTML 过滤，不能据富文本输入承诺任意 HTML 保留。

## 无限库存（替代 0.1.0 库存模块）

用户明确要求移除批量库存并永久有货。pre_option_woocommerce_manage_stock 固定 no，产品及变体读取 manage_stock=false、stock_status=instock；保存前 normalize 写入原生属性并清空数量，订单不会扣减。显式 Availability::migrate 逐店经 CRUD 保存现有商品/变体，修正 lookup，使原生缺货过滤也一致。迁移不更改名称、价格、状态、订单或用户。库存类和管理写操作已删除。停用插件后现有原生元数据仍是不管理库存；旧库存数只在升级前完整备份中保留。

## 店铺 Logo 与搜索可见性（0.2.1）

每店 option gsm_logo_id 是统一 Logo 引用，缺省回退旧 theme_mod custom_logo，不做批量图片迁移；theme_mod_custom_logo 输出映射到店铺值，原生 theme_mods 新增/更新会回写该店引用。设置保存先校验本店附件/主题，再切主题和设置 Logo。通过默认值保留未提交字段。Logo 和 blog_public 不属于共享网络配置。

搜索可见性直接保存原生 blog_public（0/1），由 WordPress 原生机制同步网络 blogs.public 与 noindex，不额外建立 robots 配置。修改原生阅读设置也触发缓存清理。这个开关不等于禁止爬取或隐私访问控制。

Media 适配店铺设置和商品编辑的原生 wp.media，不实现第二套媒体查询/上传。所有媒体 AJAX 附加 gsm_media_store 和按店 nonce；仅网络管理员及允许操作可进入，原生处理器继续校验 upload_files 和原生 nonce。库、上传、编辑均在目标店上下文，未支持的删除等操作直接拒绝，避免附件编号碰撞误作用于主店。原生 JSON 响应通过 wp_die 直接退出，无法经过通常的 finally，因此适配器在原生 die handler 前恢复，并注册 shutdown 兜底；其他跨店操作仍使用 Stores::within 的 finally。媒体弹窗中永久删除请在所属店原生媒体库完成。

admin_init 先校验授权但保持主店上下文，直到对应 wp_ajax 媒体 action 执行前才切换；async-upload.php 的直接处理使用 admin_init 最末优先级。这样避免其他插件/主题的一次性后台初始化在目标店触发跳转。QA 原生初始化守卫会检查这个边界，不能提前在 admin_init 开头切换店铺。

## 店铺列表、弹窗与域名（0.2.2）

中央店铺设置改为单表，每店一行。新增/编辑是 HTML dialog 内的同源 iframe，只加载一个目标店表单；父页不创建 wp.media 实例，编辑页媒体授权固定到该店，关闭销毁 iframe，重新编辑重建原生媒体模型，避免不同店附件 ID 相同造成浏览器缓存混用。保存沿用 admin-post、网络管理员和 nonce；成功后的同源消息同时校验 origin 与 source，再刷新父列表。失败只短暂保存本管理员表单白名单字段，120 秒过期，不保存 SMTP 凭据。

Stores::domain 校验 HTTPS 主机名、网络主店保护和跨网络域名占用（包括已停用店）。保存校验全部设置后，使用原生 update_blog_details 更新分店 domain/path，再写本店 home/siteurl 为 HTTPS 根域名并清 rewrite_rules；不改网络 DOMAIN_CURRENT_SITE。未传域名或域名未变保留旧路径。Stores::create 第三个可选参数是映射域名；旧的两参数调用保留路径式 QA/兼容行为。新增界面自动生成内部标识。

域名只是原生绑定，不提供 DNS/TLS/代理自动配置或公网验证，不发起服务器端域名探测。不能将保存成功当作新域名已可访问。既有 UUID、订单来源快照和客户身份保持；正文/选项中的旧绝对 URL、旧域名 301、第三方回调由独立备份迁移流程处理，不批量替换网络数据。主店域名只读。

## 邮件投递

利用原始店铺的 WordPress / WooCommerce 发送流程和 FluentSMTP 投递，不引入第二个中央发送器。FluentSMTP 连接和凭据以主店 option 为唯一来源，分店通过 option 过滤器读取，刷新凭据写回主店，不保存分店副本。已核对 FluentSMTP 2.4.1 有请求内静态设置缓存，因此不得通过切换博客后读取多套凭据来假定隔离。分店启用发信后仍必须存在精确的发件地址路由，否则阻止发送，不落入主店默认连接。当前店铺和订单来源决定品牌；后台跨店操作必须在作用域退出前完成订单操作。WooCommerce 原生各店延迟任务仍在本店上下文执行。

新店 `mail_enabled=false`；打开需要有效发件与客服邮箱，管理员仍需验证 FluentSMTP 路由和 SPF/DKIM/DMARC。SMTP 强制发件身份可能覆盖过滤器，故不能以本插件设置成功代表邮件已真实送达。失败告警日志不含正文、地址或重置令牌。

## 范围外

SSO、多地址簿、积分与营销、支付与共享卡片、退款、第三方运单、自动继承同步、细粒度客服授权、远程多 WordPress 连接器、自动 DNS/HTTPS、自动域名迁移均未实现。

订单状态仍会触发 WooCommerce 原生通知；无限库存不代表收款成功，支付流程仍未接入。
