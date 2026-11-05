# 本地运行、迁移与回滚（0.5.9）

用户要求后续改动只在本地验证，收到新的明确发布通知前不得部署、连接生产执行转换或清理旧站点表。线上版本仅沿用此前记录 0.2.2，本次未连接核实或变更；根目录 DEPLOYMENT.md 为历史生产记录，不能当作本次执行授权。

## 当前实际环境

项目 `/Users/dodo/Documents/wordpress-sites/group-shop`；独立私有 QA `/private/tmp/group-shop-manager-qa`，Docker context desktop-linux，项目 group-shop-manager-qa。当前 `GSM_QA_MULTISITE=0`，WordPress 原生站点编号始终 1。测试环境 WordPress 7.0.4、PHP 8.3、WooCommerce 11.1.2、FluentSMTP 2.4.1。

| 品牌 ID | HTTP 本地域名 |
|---|---|
| 1 | www.main.local |
| 6 | www.branda.local |
| 7 | www.brandb.local |
| 9 | www.brandc.local |
| 11 | www.brande.local |
| 12 | www.brandf.local |
| 13 | www.brandg.local |

宿主 hosts 精确指向 127.0.0.1；Apache 80 端口只将这些主机代理到 127.0.0.1:18087。七个 Docker DNS 别名指向 QA Nginx，不访问公网同名主机。没有改系统代理、其他站点、生产、独立 trovesia-shop。主入口 http://www.main.local/wp-admin/admin.php?page=group-shop-manager&gsm_brand=0 。旧 `/wp-admin/network/` 书签在 Nginx 跳到单站后台。

启动现有卷：

```bash
cd /Users/dodo/Documents/wordpress-sites/group-shop
DOCKER_CONTEXT=desktop-linux docker compose \
  --project-directory /private/tmp/group-shop-manager-qa \
  --env-file /private/tmp/group-shop-manager-qa/.qa.env \
  -f scripts/group-shop-manager/compose.qa.yaml up -d db wordpress nginx
```

宿主既有 Homebrew Apache 也须运行。暂停用同套 compose stop，不能 down -v。不要重跑历史 bootstrap／prepare-local 安装器。源码只读挂载，环境和密码文件私有。Compose 仅在 GSM_QA_MULTISITE=1 定义网络常量，现在不开启 WP_ALLOW_MULTISITE／MULTISITE。

本地为真实前台验收已设置 woocommerce_coming_soon=no；各品牌 blog_public 保持既有状态，邮件和支付 MU 拦截保留。Coming Soon 关闭不等于生产开放，也不等于允许收录。所有验收只用合成资料、example.invalid 邮箱和 loopback 域名。

## 已执行的迁移

迁移前匹配备份：`/private/tmp/group-shop-manager-qa/backups/single-brand-20261104-e59hs1sh/`。

- database.sql：完整迁移前数据库，含旧网络表；权限 600。
- source.tgz：匹配的迁移前插件、脚本、测试和根 AGENTS；含旧 Multisite 运行代码。
- qa.env：迁移前私有环境配置，Multisite 开启。
- multisite-export.bin：通过原生 API 读取的私有序列化快照，包含敏感测试字段，不提交或展示。

先暂停写入、完成备份和原生导出／预检，准备平台管理员与品牌注册表；再关闭本地网络常量、使用原生单站上下文显式导入。`SingleMigration` 持独占锁、事务与禁发信保护，全部成功才置 gsm_single_brand_ready；出错回滚并清缓存。WordPress 配置切换到导入完成之间是维护窗口，不是无停机操作。

导入 7 品牌、67 个商品、89 个变体、5 笔订单、17 条评论（含 10 产品评论）、36 附件、90 页面、24 集合；生成 41 个原生公共父商品及相应公共变体。重新映射非主站原生 ID，订单／商品引用、页面选项、隐私页和菜单跟随精确映射。保留原域名、UUID、价格／状态／描述／图片及订单金额／地址快照；本地顾客独立用户 ID 和密码不变，员工授权转为品牌角色映射。迁移撤销原会话，后台需重新登录。

原 uploads 文件不搬动，附件继续引用已有 uploads/sites/N；旧子站表、历史 catalog 和 audit 不删除，作为迁移档案。不能把档案表误当运行数据或再启动旧同步器。

当前源下载权限、付款令牌、税率和退款为零；不据此承诺生产特殊商品／订阅／积分／会员插件的通用迁移。原先临时购物车／会话不是业务历史快照，切换后需重新建立会话；不承诺将任意第三方持久购物车结构自动合并。

本地迁移入口 scripts／tests 均有 local、.local、WP_CLI 或当前私有路径限制，不能删除保护后用于生产。已完成 run() 返回既有报告，不重复导入。旧 CategoryMigration、UserMigration、SharedTaxonomies 写入入口在 0.5.0 退休；旧测试只代表历史版本，不能再直接执行。0.5.0 在 Multisite 网页下返回迁移维护保护；未 ready 的单站不会自动转换。任意全新安装初始化不在本次迁移验收范围。

## 当前验收入口

证据总览：`qa/group-shop-manager/v050-local/LOCAL-v050.md`。

PHP 测试使用同套 compose 的工具容器，按需要只读／私有读写挂载：

```bash
DOCKER_CONTEXT=desktop-linux docker compose \
  --project-directory /private/tmp/group-shop-manager-qa \
  --env-file /private/tmp/group-shop-manager-qa/.qa.env \
  -f scripts/group-shop-manager/compose.qa.yaml --profile tools run --rm -T \
  -v /private/tmp/group-shop-manager-qa/.identity-private:/gsm-private:rw \
  wpcli --allow-root --url=http://www.main.local --user=gsm-admin \
  eval-file /gsm-tests/single-brand-data.php
```

single-brand-data 为现有迁移资料只读完整性；runtime 会创建公共／品牌商品及临时优惠券，不能随意重跑遗留 fixture。真实 HTTP 脚本必须从 `/private/tmp/group-shop-manager-qa` 执行，且校验全部目标 loopback，不能拿生产域名替换保护：check-single-brand-local.py、check-single-brand-admin-local.py、check-single-brand-reset-local.py。后两者有原生新增员工／注册／改密副作用；必须遵循报告中的 fixture 准备和清理流程，重置密钥只存私有文件。

当前测试临时员工、并发／注册顾客、零支付订单和 100% 优惠券已在验收后清理；保留一组公共档案和 A／B 商品版本供人工验收。不要将这些临时测试优惠券带到生产。根管理员浏览器会话不作清理目标；仅撤销测试顾客／员工会话。

## 成套回滚

回滚会丢失迁移后写入，应先停止写入并另备份当前数据库／源码／配置，保留之后需要合并的数据。**不能仅改 GSM_QA_MULTISITE=1，不能只降级 PHP。**

1. 在本地维护窗口停止 Web 访问，保留数据库服务及卷；确认上述备份属于本项目、本时间点。
2. 先移开当前新增 0.5.0 插件文件，再从 source.tgz 在仓库根恢复匹配插件、脚本、测试和 AGENTS。只覆盖会遗留新类文件，不能视为完全恢复。勿触及其他项目。
3. 通过匹配的本地 WP-CLI／数据库工具导入 database.sql；凭据从私有 env 读取，不把密码写进 shell 命令或日志。
4. 恢复 qa.env、旧 compose 与 Nginx（source.tgz 内）；重建 wordpress／nginx 容器以恢复网络常量，清理对象／页面／重写缓存。
5. 核对 Multisite、原网络管理员、七店域名、原数据数量和订单快照，重新登录。原上传文件未删除，无需伪造重建。

identity-v040-before 是更早用户拆分前备份，不是本次关闭 Multisite 的回滚基线。停用／卸载默认保留业务资料，也不能替代成套回滚。

## 将来生产迁移前

须先收到用户明确发布通知，再核对实际版本、插件、数据规模、支付／税务／物流／订阅组合和域名路由，另建维护、备份、迁移、验收与回滚方案。本次专用 .local 快照不是通用生产迁移器；不得把删除 Multisite 常量当成完整迁移。

## 0.5.1 本地原生 Products 验收（2026-11-05）

该版本补列表和有效编辑控件，不需要重跑单站迁移或拆分身份；生产未发布。报告／截图见 qa/group-shop-manager/v051-local/。PHP native-products-v051.php 创建本地临时公共、品牌及可变商品，再运行 check-native-products-v051-local.py；真实浏览器验证价格显隐和原生变体 AJAX 保存，native-products-v051-after.php 核对持久化。cleanup-native-products-v051.php 按私有 fixture 的明确编号、保留失败 fixture 编号和 QA051 标题前缀清理，先检查订单引用。需要重跑时读各脚本断言和私有准备流程，不直接使用当前已清理的 fixture。

本轮清理16条临时商品／变体，原父商品数恢复114，七品牌和原始资料核对保留。上述步骤只在本地；不产生真实邮件、付款、新员工或生产操作。界面版本回退使用匹配单站源码，不能拿迁移前0.4 Multisite源码替代；关闭Multisite的完整回滚仍遵守前文成套恢复。

## 0.5.2 本地批量商品验收（2026-11-05）

不重跑迁移，继续单站七品牌。本地测试顺序：WP-CLI eval-file /gsm-tests/bulk-products-v052.php（挂载私有目录到 /gsm-private），再从私有 QA 目录运行 check-bulk-products-v052-local.py；后者仅 loopback，依赖前者生成的私有 fixture，不能只运行一半。PHP覆盖混合结果、幂等、部分变体写入故障／清理／重试、回收站去重、状态冲突、权限和任务归属；HTTP覆盖原生 Apply、nonce、并发任务、暂停／刷新／继续和状态批量写入。浏览器仅操作 QA052 测试商品，截图不含凭据。

中断后先检查最近测试任务，勿直接重跑准备脚本（会把进行中的任务当最近任务）。cleanup-bulk-products-v052.php 严格限定 local＋main.local、QA052及随机后缀，订单引用检查后通过 Woo CRUD 清除本轮临时商品／变体，并只清理全部选择均属于该fixture的任务。不触碰原始商品、保留演示或其他用户任务；清理后运行 single-brand-data.php 的718项只读核对。报告见 qa/group-shop-manager/v052-local/LOCAL-v052.md。任务私有清单和凭据仅在 .identity-private，不入库或日志。

正常用户任务没有定时后台处理，保持页面打开；关闭后进入 Products → 批量任务继续。记录最后更新起24小时到期，产品本身不删除；失败项可重试，部分成功不回滚。未完整建立的版本先检查草稿并 Update；回收站版本先恢复，不能用重复添加当恢复。没有新增业务表或自动初始化流程，继续禁止生产部署。

## 0.5.3 公共／品牌统一继承验收（2026-11-05）

当前已保存品牌商品全部有公共来源，不重跑单站迁移，不改变销售商品或变体ID。products-v053-preflight.php只读检查，结果45公共记录（含2自动草稿）／71品牌版本／无未关联品牌商品。products-v053-autodrafts.php仅处理本地未保存、无来源的auto-draft，先将原元数据私有备份至/gsm-private/products-v053-autodrafts.json，再改公共归属；本轮2条，无内容／状态修改。重复执行不会再改已公共的记录。此入口没有生产迁移授权，不作为独立商品通用迁移器。

验收顺序：本地WP-CLI以gsm-admin运行products-v053.php（挂载私有目录到/gsm-private），私有QA目录执行check-products-v053-local.py，另products-v053-cron.php覆盖无登录用户的既有Woo定时写入；真实浏览器只操作QA053商品验证合并筛选。凭据、表单nonce、fixture编号仅私有文件，不写日志。HTTP读取原生表单须排除未点击的submit按钮，避免测试意外上架。

cleanup-products-v053.php仅清理QA053随机后缀临时记录，先检查订单引用，保留原商品／演示和其他用户任务。完成后运行single-brand-data.php核对718项原始资料。当前删除16条临时商品／变体及1任务，114父商品、287商品及变体保留。报告qa/group-shop-manager/v053-local/LOCAL-v053.md。旧0.5.1／0.5.2套件创建独立商品，不能直接在当前模型重跑；历史报告保留。

没有开启或恢复Multisite，无新业务表、真实邮件、支付或生产操作。要回退本轮两个自动草稿标记，需使用私有备份对照确认它们仍为未保存auto-draft，恢复对应元数据；已开始编辑则不能盲目覆盖。完整架构回滚仍必须遵循前述成套备份规则。

## 0.5.4 退休中央商品入口（2026-11-05）

本轮纯入口及旧服务清理，不重跑迁移／商品fixture。私有QA目录运行check-retired-products-v054-local.py，检查五标签、概览原生链接、旧列表／编辑跳转、无效范围404、旧保存／状态410及nonce403、集合页面仍可访问。仅GET与拒绝的旧POST，无商品业务写入；无新增fixture需要清理。浏览器核对五标签，截图及报告qa/group-shop-manager/v054-local/。

代码恢复需使用当前单站匹配版本，不恢复旧Multisite功能。商品数据与集合规则保留；原生商品管理和批量操作仍可使用。共享集合媒体／编辑器资源不能因删除ProductAdmin一并删除。集合评估仅文档，不作为实施或生产授权。

## 0.5.5 分类范围与集合层级验收（2026-11-05）

继续单站.local私有环境，无业务迁移或部署。先以gsm-admin运行collections-v055.php（挂载.identity-private至/gsm-private），生成QA055随机后缀的公共／品牌销售商品和共享／私有集合；私有清单collections-v055.json保存测试编号和原分类／成员关系／各品牌规则快照，不提交。若文件已存在先检查／清理，禁止覆盖未清理fixture。接着运行native-taxonomy-v055.php检查列表SQL计数和跨品牌全局层级缓存，再从/private/tmp/group-shop-manager-qa运行check-taxonomy-v055-local.py验证真实分类分页、编辑／拒绝POST和归档。

cleanup-collections-v055.php只清理当前随机后缀QA055商品和词条，订单引用预检后Woo CRUD删除；移除各品牌中本轮集合规则，逐项比较原词条ID／名称／slug／parent、原分类标签成员关系及原集合规则。随后运行single-brand-data.php完成原始迁移资料核对。源码和测试证据见qa/group-shop-manager/v055-local/。浏览器仅核对列表与fixture编辑，不改业务集合或用户会话。

不重跑旧Multisite分类／集合套件。没有term迁移或公共目录重建，回退代码也不应自动清除用户后来设置的parent；若需回滚用户实际层级变更，应备份并显式恢复对应term parent。改变层级会改变原生归档URL，现有ID引用保留；本轮没有上线或生产旧URL重定向配置。

## 0.5.6 原生页面分店验收（2026-11-05）

最新范围仅原生Pages按店铺独立管理，没有模板继承／同步或新用途绑定。继续使用.local私有环境。WP-CLI以gsm-admin运行native-pages-v056.php（挂载.identity-private至/gsm-private）生成QA056随机后缀的合成主店／A／B页面，私有native-pages-v056.json保存编号和原页面／设置快照；文件已存在时先检查／清理，不能覆盖。随后从/private/tmp/group-shop-manager-qa运行check-native-pages-v056-local.py验证列表、Gutenberg REST、同店新建／独立编辑、拒绝写入及签名草稿预览。

cleanup-native-pages-v056.php仅清理当前后缀与私有编号的合成页面及其原生修订；逐项比较原页面ID、标题、正文、摘要、状态、parent、内部slug和作者，品牌注册表及原业务设置保持。缓存generation／清理时间／原因属于运行记录，允许随测试保存更新，不把其变化误当业务迁移。最后运行single-brand-data.php核对原始迁移资料。

没有新增业务表、生产操作或政策数据转换。之前未交付的模板方案fixture已清理，相关原页面及业务设置另已私有核对保留；品牌sequence继续单调递增，不回用临时测试分配过的编号。停用／卸载继续保留页面和品牌数据，回退本轮代码不删除页面。

## 2026-11-05 本地业务重置与 Chanel 公共目录

用户明确授权清空业务数据，保留七品牌、后台管理员及环境配置，并选择“只导入公共档案，暂不生成销售版本”。本轮已完成一次性本地重置：原5笔HPOS订单、289条商品／变体、155条其他内容记录、7个顾客、84个业务词条及旧子站档案中的业务行已清理。保留2个后台员工／管理员账户、原密码与身份、七品牌注册及联系／邮件／集成配置；保留主题配置记录和原表结构，不重建数据库。页面／菜单／集合／Logo等已删除资源绑定归零；系统默认分类重建，不自动恢复政策或Woo功能页面。

用户 CSV `wc-product-export-27-8-2026-1787800592981-working.csv` 的72件简单商品已通过Woo原生CSV导入器新建为公共档案，保留SKU、价格、长短描述、349项本地属性、分类层级及补充元数据。72张图片全部从本机香水素材目录导入并校验原图SHA256；无原网站连接，源CSV哈希不变。来源ID只作为元数据记录，不复用其他安装的原生ID；没有生成任何品牌销售版本，七店前台均暂不展示商品。

私有成套备份与运行清单：`/private/tmp/group-shop-manager-qa/imports/20261105-chanel-6bd9d69e/`（backup/database.sql、uploads.tgz、source.tgz、qa.env及verified.json）。361张表、旧上传文件、源码和环境文件已备份并校验，包含敏感资料，不提交或公开。当前有效数据基线为72公共／0品牌／72图片／0顾客／0订单／0页面／0菜单／0集合；先前“718项原迁移资料保留”的验收仅为重置前历史，不得继续作为当前数据断言，旧私有fixture中的商品／顾客编号也不可直接复用。

1,662项逐字段／图片字节／归属／配置／旧档案清理核对通过，另有24项七域名与实际媒体HTTP核对，以及原生后台72件公共商品和缩略图浏览器检查。报告：`qa/group-shop-manager/import-public-chanel-20261105/REPORT.md`。软件版本仍0.5.7；仅本地数据维护，未发布或连接生产。

本轮维护入口均位于 scripts/group-shop-manager/，不由插件或HTTP加载：local-catalog-common.php、reset-business-local.php、import-public-csv-local.php、finalize-public-csv-local.php、verify-public-csv-local.php。只接受WP_CLI、local、DB_NAME=gsm_qa、wp_前缀、受控平台管理员、精确.local注册表、/gsm-import私有挂载和GSM_LOCAL_CATALOG_ACTION相应动作；执行前必须校验源文件及备份哈希。reset-started／reset-complete标记阻止自动重复清空；导入仅支持本轮检查过的简单商品，图片逐项记录，商品按本批来源SHA和SKU跳过已完成项；完成标记禁止重复导入。不能把此一次性授权用作后续自动清空许可。

执行时暂停本项目wordpress/nginx防并发，数据库服务保留；使用原生Woo／WP CRUD清理当前对象，inactive旧子站业务表只清行，不switch_to_blog或删除表。保留基础角色／品牌配置、系统taxonomy、主题样式记录与上传访问控制文件，旧媒体／日志／购物会话／任务和已删除资源绑定清理。Woo维护命令会生成仅含customer字段的运行会话，finalize清理并停用该CLI进程的shutdown会话回写；之后新访问产生会话属于正常行为。

回滚须先暂停Web并另备份导入后的状态，恢复上述database.sql＋uploads.tgz及匹配的源码／环境，刷新缓存再恢复服务；不能仅导入旧商品或开启Multisite。备份位于私有目录，密码／顾客资料不得输出到报告。不要重跑旧single-brand-data.php或旧fixture清理脚本来“修复”新基线。页面、菜单和品牌销售版本后续由用户重新建立，不自动播种。

## 0.5.8 展示继承与独立加购验收（2026-11-05）

本轮开始实际已有72公共＋72品牌商品，不能按旧清空基线恢复0品牌。只使用新合成商品与私有清单，不触碰用户商品。验收顺序：product-experience-v058.php创建QA058随机后缀公共／A／B／变体，并保存当前全部posts／postmeta／品牌注册及业务bank快照；check-product-experience-v058-local.py验证真实原生表单和游客购物车；浏览器实际覆盖保存再取消；cleanup-product-experience-v058.php校验ID／随机后缀及订单引用，清理fixture及含其商品的会话，并比较原资料快照。

order-activity-v058.php另建3笔零金额无顾客订单，验证店铺查询／缓存隔离，在finally中用Woo CRUD删除并恢复原缓存，核对原HPOS行；不付款、不发真实邮件，MU拦截保留。此脚本若中断，先看私有order-activity-v058.json，不重复建立订单。商品fixture文件存在时禁止重新初始化或覆盖。

52项展示／加购运行和HTTP检查、5项订单统计、原生浏览器保存及60个PHP／JS／Python语法核对见qa/group-shop-manager/v058-local/。未对业务资料批量写覆盖标记、复制文案或继承规则。软件回退必须保留本轮之后用户编辑的资料，不删除presentation覆盖标记或加购规则来冒充回滚；完整备份仍遵守成套恢复规则。仅本地，禁止自动发布。


## 0.5.9 SEO隔离复验（2026-11-06）

仍仅本地，保留邮件／支付MU拦截。当前144父商品为72公共＋72主店版本；不要重跑旧重置或迁移fixture。测试前完整SQL备份为/private/tmp/group-shop-manager-qa/.identity-private/seo-v059/before-qa.sql（权限600，含敏感资料，不提交／输出）。本轮私有清单seo-isolation-v059.json已在成功清理后删除；runtime.json／cleanup.json另保留于同一私有seo-v059目录，并将无敏感结果复制至版本QA目录。

WP-CLI继续使用当前compose、gsm-admin、--url=http://www.main.local及.identity-private:/gsm-private:rw挂载，按以下顺序运行：

1. seo-isolation-v059.php：必须fresh fixture；创建随机QA059后缀的A／B销售商品／变体、页面／文章、共享分类／标签、私有集合、菜单及评论，保存八表与七品牌bank完整快照。仅临时将A／B blog_public=1及菜单位置指向合成菜单；私有清单存在即拒绝重建。
2. check-seo-isolation-v059.php：运行对象／查询／原生Woo REST与聚合缓存检查，准备合成页面的旧跨店业务链接及旧slug。改动仅限清单内合成记录。
3. 从/private/tmp/group-shop-manager-qa运行scripts/group-shop-manager/check-seo-isolation-v059-local.py：固定连接127.0.0.1:18087，以七个注册.local Host请求；覆盖允许收录后的Sitemap、canonical、JSON-LD、页面／商品／变体、分类／集合、评论、统计、菜单、正文、搜索／Feed与旧slug。CSS／JS／图片不列入跨域失败；不会追踪生产或任意外部URL。
4. cleanup-seo-isolation-v059.php：校验随机前缀、私有ID、订单引用和并发配置差异；只删除本轮记录并恢复A／B索引及菜单、集合配置，逐行比较八表和七品牌业务bank。失败时检查私有清单，不用SQL快照覆盖用户测试期间的新工作。

本轮46运行检查、141 HTTP请求中的315检查通过；清理24内容／商品／菜单项、7词条、3评论，八表和原业务bank核对一致，仍144父商品。复验可重复但必须新fixture，并在完成后清理；禁止清理用户后来创建的版本。作者Sitemap关闭属于当前SEO行为；回退源码不删除业务数据，生产发布仍需新的明确授权和实际环境验收。
