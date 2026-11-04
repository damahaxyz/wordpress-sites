# 部署、验证与回滚

**0.4.0 当前仅本地开发。用户已撤销后续自动上线授权，等全部改完明确通知后才允许发布。下文历史生产步骤不得在当前任务执行。**

## 0.4.0 账户拆分迁移与回滚（仅本地授权）

这是数据结构及身份语义升级，不能按旧版本“替换代码无用户迁移”的说明操作。当前本地已经显式完成初次迁移；**没有生产授权，也未接触生产。** 旧用户相关验收脚本默认共享密码／自动加店，已不代表当前行为，不能重跑后据其断言恢复旧策略。

迁移前私有匹配备份位于 `/private/tmp/group-shop-manager-qa/backups/identity-v040-before/`，包括 database.sql 与 plugin.tgz。未导出生产数据；测试密码、重置令牌在 `.identity-private/`，不得提交或展示。代码新版本建身份索引，但 ready 未开启时顾客注册／登录关闭，网络超级管理员保留救援登录；后台访问和激活不会自行拆分用户。

流程：维护期间先备份并暂停用户／订单写入；读取 `UserMigration::plan()`，未知角色、停用归属、多网络成员或无所属店顾客会阻止，需明确身份后再迁移，不能跳过错误或猜归主店。显式 `run()` 持迁移锁、禁用邮件、按源保存完成检查点，全部成功才开启 ready；部分失败可按检查点续跑，但不能宣称多表／多店全事务。原管理员／Shop Manager 保留员工管理 ID 与授权，customer-only 店拆分独立顾客；超级管理员身份保持。

仅供当前本地复验的安全入口（脚本严格限制 local＋主店 .local，不能拿去生产或删除限制）：

```bash
cd /Users/dodo/Documents/wordpress-sites/group-shop
DOCKER_CONTEXT=desktop-linux docker compose \
  --project-directory /private/tmp/group-shop-manager-qa \
  --env-file /private/tmp/group-shop-manager-qa/.qa.env \
  -f scripts/group-shop-manager/compose.qa.yaml --profile tools run --rm -T wpcli \
  --allow-root --url=http://www.main.local --user=gsm-admin \
  eval-file /gsm-tests/migrate-identities-local.php
```

已完成的账户重跑不再拆分。新 ID 一次复制密码哈希和默认地址起点，不能读取明文密码，之后不再同步；旧共享顾客会话撤销。订单以 Woo CRUD 修改顾客 ID，保留状态、金额及地址快照；产品评论作者、所属店持久购物车、下载权限、既有付款令牌随身份迁移，令牌写入仍用 CRUD，不发生支付。自定义会员积分／订阅／第三方元数据不自动猜测搬迁。

回滚时保持维护窗口，先另备份当前状态，再恢复该迁移前完整数据库与匹配 plugin.tgz 源码并清理缓存，验证旧用户／订单归属；只回退 PHP 会让同邮箱解析和已拆分 ID 失配。恢复前备份会丢弃备份后的新变更，应先评估并保留；不得在线随意降级。停用／卸载仍默认保留业务数据，不能当成迁移回滚。

当前测试入口：`isolated-users.php`（需私有 `/gsm-private` 挂载）→ `prepare-identity-http.php` → `check-isolated-users-local.py`；后者从 `/private/tmp/group-shop-manager-qa` 执行。原生员工＋并发验收 `check-native-identity-admin-local.py` 后运行 `native-identity-admin.php` 核对并清理临时用户；迁移完整性 `identity-integrity.php` 用临时合成资料、经典与 HPOS 订单及明确假令牌，finally 清除。完整证据见 `qa/group-shop-manager/v040-local/LOCAL-v040.md`。旧 v036 共享身份测试及旧发布器 fixture 仅为历史记录，不适用于当前模式。

原生姓名／邮箱编辑仍使用当前店解析；网络用户编辑其他店顾客时不要从当前店执行 Woo 邮箱确认／游客订单关联，界面与服务器已限制。所有新第三方按邮箱查询（含资料导出／会员／SSO）必须接入本店索引；原生 get_user_by('email') 在同邮箱多行时无店铺含义。线上以后另获授权时应先核对实际 WordPress／Woo 版本和插件组合，再制定生产迁移，不直接沿用本地脚本。

## 当前本地域名（2026-11-04）

用户最终确认改用 `.local`，现有代理包含 `*.local` 例外，不需要改代理。当前使用 **HTTP、80 端口**，没有安装 HTTPS 证书。浏览器打开 http://www.main.local/wp-admin/admin.php?page=group-shop-manager 。同一 gsm-admin 账户／原密码可以登录各店，域名间分别登录。

| blog_id | 本地域名 | 原店铺／数据 |
|---|---|---|
| 1 | www.main.local | 原 Group Shop QA 主店 |
| 6 | www.branda.local | 原 Brand A renamed |
| 7 | www.brandb.local | 原 Brand B |
| 9 | www.brandc.local | 原 QA Shared Store，保留店名 |
| 11 | www.brande.local | 新建 Brand E 空白测试店 |
| 12 | www.brandf.local | 新建 Brand F 空白测试店 |
| 13 | www.brandg.local | 新建 Brand G 空白测试店 |

用户已把这七个主机名写入本机 hosts 的 127.0.0.1 行。Apache 原先占用 80；在 /opt/homebrew/etc/httpd/httpd.conf 末尾 Include 本项目 scripts/group-shop-manager/apache.qa-domains.conf，先保留原默认站，再对七个精确 ServerName／Alias 代理至 127.0.0.1:18087，并 Require local。其他 Host 保持原站点，本轮实测 127.0.0.1 仍跳转原 www.tikool.com。没有改独立 trovesia-shop 或生产。

QA Compose 的 GSM_QA_DOMAIN 可配置主网络域名；私有 .qa.env 当前为 www.main.local。未配置时仍保留 localhost:${GSM_QA_PORT} 的历史安装默认。不能全局定义 WP_HOME／WP_SITEURL。七店原生 domain/path、home/siteurl 与网络 wp_site／siteurl 已一致，均根路径。容器 Nginx 的七个网络别名使 PHP／WP-CLI 内部请求直接到 QA 容器，避免公网解析。

**启动现有环境**（Docker Desktop 已运行）：

```bash
cd /private/tmp/group-shop-manager-qa
DOCKER_CONTEXT=desktop-linux docker compose --project-directory "$PWD" --env-file .qa.env \
  -f scripts/group-shop-manager/compose.qa.yaml up -d db wordpress nginx
```

本机 Homebrew Apache 也需要运行；若暂停，使用 `brew services start httpd` 启动原有服务。这里只启动现有环境，**不要重跑旧 fixture 安装器**。暂停测试容器时对同一 compose 使用 stop，不执行 down -v。bootstrap 已支持读取最终 QA 主域名，但不会自动重建这份七店映射／数据或安装宿主 Apache Include。临时 QA 目录／卷被清理后的全新重建需按实际需要重新映射，不能把下方旧 localhost fixture 命令直接用于当前网络。

迁移已进行序列化安全 URL 替换，范围为各店选项、非历史订单的文章／商品正文与元数据、term 描述／元数据；保留 GUID、订单快照、用户和网络审计表。每店 upload_url_path 显式设为本店 `http://域名/wp-content/uploads`，不包含 sites/{id}，由原生 Multisite 追加目录。这可避免 switch_to_blog 的跨店媒体继承主站 WP_CONTENT_URL；图片文件、附件编号和实际上传目录不搬动。

原 localhost 根地址跳转新主站；原 /brand-a/、/brand-b/、/qa-shared-store/ 路径不再作为店铺入口。生产店铺编辑 UI 仍按既有规则绑定 HTTPS，本次本地 HTTP 映射经受限 QA CLI 完成，没有放宽插件的生产校验。

当前只读验收：

```bash
python3 /Users/dodo/Documents/wordpress-sites/group-shop/scripts/group-shop-manager/check-local-domains.py
```

脚本要求私有 QA 环境标记和七域名均解析 127.0.0.1；无代理 HTTP、限制跳转目标、私下读原测试密码，只注销自己的会话。41 项通过，报告与浏览器截图在 qa/group-shop-manager/local-domains-20261104/；另核对七店原生 URL／数据不变量、Apache／Nginx 语法和容器内 DNS／HTTP。历史集成／HTTP 脚本大多严格限定 localhost，当前会拒绝执行；保持保护，后续需显式适配固定本地域名和店铺定位，不能简单去掉保护或伪装主站地址。

**私有备份与回退**：/private/tmp/group-shop-manager-qa/backups/domains-20261104T040254Z/。database.sql 为改域名前四店 localhost 网络，database-before-local.sql 为已补三店但仍用 .com 的中间状态；原 .qa.env、compose.qa.yaml、nginx.qa.conf、httpd.conf 及迁移／核对报告同存，目录 700、数据库及私有文件 600。凭据和 SQL 不复制到源码或公开报告。

若明确需要回到原 localhost：先备份回退前新增数据，在 QA 维护窗口同时恢复原数据库、.qa.env 和 QA compose／Nginx 配置，重建仅此项目的 wordpress／nginx；恢复原 httpd.conf 并语法检查、平滑加载。完整数据库回退会撤回 E／F／G 新站和回退点之后的数据，不能仅修改网络常量或只退源码。此次未变更上传文件，原文件卷保留；用户 hosts 修改独立管理。当前不执行回退。

## 环境与安装模式

主店：`root@contabo2:/root/wordpress-sites/group-shop`，域名 `www.animecats.online`。WordPress 7.0.4 / PHP 8.3 / WooCommerce 11.1.2 为本次验收基准，实际以后更新要记录到 HANDOFF。

历史 0.1.x 验收曾在本地 Docker 未启动时使用 contabo2 上的独立 QA 项目 `group-shop-manager-qa`，独立数据库/卷和 127.0.0.1:18086，不允许暴露到公网。QA 只复制可信 WooCommerce 程序，不复制生产客户或订单。

## 0.3.7 主商品发布退休验收

仅在现有 .local 隔离 QA，未发布生产。用现有 tools WP-CLI（--allow-root --url=http://www.main.local --user=gsm-admin）运行 /gsm-tests/catalog-state.php 记录只读摘要、/gsm-tests/retired-publisher.php 验证退休 API 与本店 CRUD；从私有 QA 目录执行 python3 scripts/group-shop-manager/check-retired-publisher-local.py，再次运行只读摘要。25 集成＋17 HTTP＋15 数据保留核对通过，三份商品／变体、附件、映射摘要一致。报告与浏览器截图在 v037-local。临时商品 finally 清除，HTTP 临时会话注销。

旧发布处理器和 API 均删除，现有商品、变体、媒体及历史映射保留，无新清理迁移。旧映射仍用于分类／标签归属同步，禁止把退休发布功能误解为授权清空 gsm_catalog 或解除既有分类规则。未来整体上线仍遵循前序共享分类迁移的备份要求，回退本次代码会恢复旧发布功能，不需要反向迁移本轮业务数据。

**历史套件限制更新：** product-editor.php、shared-taxonomies.php、collections-v032.php 等用 Catalog::publish 生成 fixtures，该 API 已删除；加上现有 localhost 前提，它们不再是当前可直接运行的重建／验收步骤。不可放宽保护后执行或为测试恢复发布器。下文原命令保留作历史记录；当前这轮入口为 retired-publisher.php、catalog-state.php、check-retired-publisher-local.py。新验收必须使用明确隔离环境与原生 CRUD fixture。

## 0.3.6 原生网络用户验收

仍在现有 .local 隔离 QA，保留域名、路由、数据库和容器配置。三个生产源码 PHP 文件语法通过；26 集成＋22 HTTP 检查，报告 qa/group-shop-manager/v036-local/。用既有 tools WP-CLI（--allow-root --url=http://www.main.local --user=gsm-admin）运行 /gsm-tests/native-users.php，保存成功 JSON 为 native-users.json，再从私有 QA 目录运行 python3 scripts/group-shop-manager/check-native-users-local.py。前者仅允许 local 和准确主店 home，后者验证 QA 目录、GSM_QA_DOMAIN、loopback DNS 及跳转白名单，不能改成生产目标运行。

PHP 套件只对临时合成用户测试注册／跨店角色／默认地址／密码失效，并 finally 删除该用户；不改真实或已有测试用户密码。HTTP 为只读页面检查，逐域注销自己的临时会话。网络用户原 Sites、编辑、搜索和权限保持，来源不可编辑，不执行回填。新增列依赖 WP 6.8 的 network-specific hook，符合插件原 Requires at least 6.8。

无业务数据迁移。回退本轮代码只恢复客户标签／列表，不需要恢复用户表或清理新增元数据；未来整体发布回滚仍遵循此前共享分类等改动的匹配备份要求。仍无发布授权，不运行 deploy-* 或任何线上用户操作。

## 0.3.5 原生 Reviews 验收

本轮在当前 .local 隔离 QA（主店 www.main.local，Brand A/B 为 www.branda.local／www.brandb.local，宿主解析 127.0.0.1）进行；保留既有映射与 Docker 环境。新验证不再使用旧 localhost 路径，无生产连接或部署。

在私有 QA 根目录，用既有 compose／.qa.env／desktop-linux 的 WP-CLI tools 服务执行 `--allow-root --url=http://www.main.local --user=gsm-admin eval-file /gsm-tests/native-reviews.php`，将成功 JSON 保存到项目 qa/group-shop-manager/v035-local/native-reviews.json；再执行 `python3 scripts/group-shop-manager/check-native-reviews-local.py`。前者要求 local 环境和准确主店 home，后者核对显式本机目录、GSM_QA_DOMAIN、loopback DNS 和跳转白名单，不能改成生产域名运行。

18 集成＋32 HTTP 通过；浏览器另外核对主站与 Brand A 店铺列、原生审核／回复控件。合成评论审核后恢复原始通过状态；会话单独注销，未清除用户其他会话。数据不迁移；回退本次代码仅恢复旧 UI／审核处理器，无新评论字段需清理，已有评论、评分、回复与历史审计保持。整体待发布版本回滚仍遵循此前共享分类等迁移的完整备份要求。

## 0.3.4 原生订单验收

无订单业务数据迁移，不删除历史订单、备注或审计。旧订单标签回到概览，旧写处理器拒绝；原生权限与通知接管状态和备注，不再使用中央有限状态白名单。回退本次代码会恢复旧 UI／处理器，不需要反向迁移订单；未来整体发布仍须满足此前共享分类等版本的完整备份要求。

在原 localhost:18087 合成网络执行 qawp eval-file /gsm-tests/native-orders.php，将 JSON 存到 qa/group-shop-manager/v034-local/native-orders.json，再从私有 QA 目录运行 python3 scripts/group-shop-manager/check-native-orders-local.py。报告 31 集成＋29 HTTP，检查目标店 HPOS／传统 URL、原生订单／备注、旧处理器拒绝和来源权限。脚本只允许原隔离地址，不可改成生产域名执行。

检查后发现 QA 站点已被外部操作改为 www.main.com／www.branda.com 等独立域名；未回改。后续原地址 HTTP 重跑被登录／版本断言终止，分店浏览器验证未完成。当前环境不再满足这些脚本的 localhost 前提，重跑前需先确认本地域名映射与隔离路由，不能直接访问公网同名域名。详见 HANDOFF 与 v034-local/LOCAL-v034.md。仍无上线授权。

## 0.3.3 原生归属提示验收

只有只读界面和样式变化，无业务数据迁移；仍仅本地。真实 HTTP 核对原生分类／标签列表、集合及普通词条标记、原生编辑页、集合管理链接和既有分店重定向；临时验收会话独立注销。10 项核对及浏览器排版／链接验证通过，报告见 qa/group-shop-manager/v033-local/LOCAL-v033.md。

不需要重跑合成数据安装器或执行共享分类清理，更不能据此发布线上。未来获明确发布通知后仍按既有备份与部署流程处理。原生页面只列当前站点，跨店汇总仍通过中央集合列表，不是新增跨店原生查询。

## 0.3.2 集合本地验收与兼容

只在私有 /private/tmp/group-shop-manager-qa、desktop-linux、loopback 18087 运行，不连接生产。集合没有新业务表；新字段是每店 gsm_collections 的 shop_filter、deleted_at、trash_state，旧集合缺省不进入新增 Shop 筛选。已有归档 slug／parent 保持，新集合自动生成内部地址。上线／回滚约束与 0.3.1 的共享词表备份要求保持，当前仍无发布授权。

在 bootstrap 和 prepare-local.php 后运行 collections-v032.php、product-editor.php、shared-taxonomies.php；将 JSON 报告存到项目 qa/group-shop-manager/v032-local/ 对应 collections.json、product-editor.json、shared-taxonomies.json。HTTP 脚本从该目录读取 fixtures，必须在私有 QA 目录运行：

```bash
cd /private/tmp/group-shop-manager-qa
python3 scripts/group-shop-manager/check-collections-local.py
python3 scripts/group-shop-manager/check-product-editor-local.py
```

两脚本严格限定 localhost:18087、私下读测试密码、finally 注销自己的 HTTP 会话，不输出 Cookie／凭据。PHP 套件调用方式沿用下节 qawp。报告 36 集合／动态区块、30 集合 HTTP、34 商品、29 共享分类、25 商品 HTTP，共 154 项。浏览器另外真实创建、媒体选封面、手选、删除恢复；Chrome 实测原生区块选择／预览／保存草稿。

匿名 Shop 验收需要合成 Brand A 关闭 Woo 的 Coming soon（woocommerce_coming_soon=no），否则只会看到准备上线页面。collections-v032.php 只对本地合成该店设置此项；blog_public 禁止收录、邮件／支付 MU 拦截不变。生产站点可见性不在本次改动范围，不复制 QA 设置。

本次内置浏览器没有加载 WordPress 原生 blob 编辑画布；普通集合后台及媒体控件可正常使用。Chrome 验证画布、区块和预览正常；未降低浏览器或服务器安全策略。原生区块测试草稿与合成前台专题页保留在本地供检查，未修改现有首页模板。

将来收到新发布通知后，仍先备份数据库／代码／uploads，核对既有集合规则、slug、成员、原生共享分类投影，再发布并做真实 HTTP。回退 0.3.1 不理解回收站标记和新增入口，需恢复匹配备份或明确处理删除状态，不能认为只退源码即可完整回滚。

下文 0.3.1／0.3.0 操作记录属于历史基准；当前测试报告和 fixtures 以 v032-local 为准。

## 0.3.1 本地验收与未来升级注意

当前本机 QA 继续使用 loopback 18087、desktop-linux、私有 /private/tmp/group-shop-manager-qa；邮件／支付拦截保持。旧 integration.php 和 v030 分类验收仅适用历史独立词表方案，不可用于 0.3.1。新网络先用下文 bootstrap 建立，再运行本地限定、不会删除既有站点的 prepare-local.php。以下 qawp 仅用于该私有合成 QA，绝不用于生产：

```bash
cd /private/tmp/group-shop-manager-qa
qawp() {
  DOCKER_CONTEXT=desktop-linux docker compose --project-directory "$PWD" --env-file .qa.env \
    -f scripts/group-shop-manager/compose.qa.yaml --profile tools run --rm -T wpcli \
    --allow-root --user=gsm-admin "$@"
}
qawp eval-file /gsm-tests/prepare-local.php
qawp eval-file /gsm-tests/product-editor.php > qa-product-editor-v031.json
qawp eval-file /gsm-tests/shared-taxonomies.php > qa-shared-taxonomies-v031.json
qawp eval-file /gsm-tests/store-settings.php > qa-store-settings-v031.json
qawp eval-file /gsm-tests/store-list.php > qa-store-list-v031.json
```

HTTP 脚本 check-product-editor-local.py 从项目 qa/group-shop-manager/v031-local/{product-editor,shared-taxonomies}.json 读 fixtures，所以重建时先将上述前两份报告放入对应项目路径，再在私有 QA 目录执行该脚本。它只接受 localhost:18087，私下读取测试密码并在 finally 注销自己的会话，不输出 Cookie／凭据。包含公共 API 上下架隔离、无效表单／权限／图片归属、原生分类 AJAX／表单同步。浏览器还须验证本店媒体上传／选择与富文本插图实际 URL、变体批量填价保存、Visual／Code、Code 工具栏、图片排序及响应布局。当前报告见 v031-local/LOCAL-v031.md。

未来若收到新的明确线上发布通知：先备份匹配数据库、代码和 uploads；维护窗口内先 CategoryMigration::run() 处理旧集合映射与退休元数据，再 SharedTaxonomies::reconcile(true) 汇集并投影唯一主站词表，核对集合、默认分类、映射商品归属和本地图片。普通网络管理员 admin_init 也会执行迁移，不能把上传代码与数据迁移当成无关步骤。当前不要执行这些线上流程。

汇集会把分店独有普通词条纳入主站；同 slug 以主站内容为准，原默认分类映射主站默认，既有分店内容差异及映射商品归属会统一。保留原生 term ID、集合定义与其他元数据；集合不共享。若普通共享词条与集合 slug 冲突则显示同步错误，处理命名后重试，禁止静默覆盖或直删核心表。用户新决定已经授权本地合成数据转换，未授权线上执行。

修订号仅在该批次投影与商品归属全部成功后写入；失败在后台展示并下次网络管理员请求重试，没有跨店事务承诺。停用站点重新启用后补做。完整回滚需要匹配数据库／代码／uploads，源码退回 0.3.0 不会撤销合并的词表、关系和复制图片。停用／卸载不会删除业务数据。

下面的 0.3.0／0.2.x 章节为历史操作说明，不能覆盖本节最新共享分类语义与只本地约束。

## 本机 QA（0.3.0）

Docker Desktop context 为 desktop-linux；独立目录 /private/tmp/group-shop-manager-qa，通过四个符号链接引用本项目 plugin/theme/scripts/tests。该目录名是 bootstrap 的保护条件；不要在 group-shop 根目录运行合成测试。首次建立链接后：

```bash
DOCKER_CONTEXT=desktop-linux bash /private/tmp/group-shop-manager-qa/scripts/group-shop-manager/qa-bootstrap.sh
```

默认新 QA 使用 localhost:18087；老的 18086 QA 仍可由 .qa.env 中 GSM_QA_PORT 指定。脚本生成 root 私有的测试凭据（本机为当前账户私有，权限 600）、独立数据库/WordPress 卷，使用官方固定版本 WooCommerce/FluentSMTP，绝不挂载生产数据库/卷。目录中的 .qa.env/.qa-admin-password/日志不进入 Git。MU 拦截发信并关闭可用支付，不能部署生产。

如果本机代理 DNS 把官方域名解析为 198.18 私网地址，WP 下载器会安全拒绝。不要关闭校验；从宿主用正常 HTTPS 下载官方 woocommerce.11.1.2.zip、fluent-smtp.2.4.1.zip 放 QA 的 qa-packages/，bootstrap 会只读挂载这些包本地安装。

```bash
cd /private/tmp/group-shop-manager-qa
DOCKER_CONTEXT=desktop-linux docker compose --project-directory "$PWD" --env-file .qa.env \
  -f scripts/group-shop-manager/compose.qa.yaml --profile tools run --rm -T wpcli \
  --allow-root --user=gsm-admin eval-file /gsm-tests/integration.php > qa-report-v030.json
```

历史 0.3.0 的 integration.php 使用合成用户/商品/订单（0.3.1 禁止执行该旧套件，使用上节新流程），重建 brand-a/brand-b；只有 WP_ENVIRONMENT_TYPE=local 且 localhost:18086/18087 才执行。接着运行 store-settings.php、store-list.php；HTTP 使用 check-qa-collections.py 与 check-native-admin-local.py，后者读取本地 QA 密码，不输出 Cookie/密码，成功后注销该次 HTTP 会话。UI 需验证原生新增分类、集合读取本店新项，以及旧分类入口不出现。

当前环境为本机真正容器，不是 SSH 转发；验收后保留运行供用户浏览，端口只绑定 127.0.0.1。要暂停仅 stop 本 QA 项目，不停其他本机服务。临时目录若已被清理，先确认只针对本合成 QA 再重建，不能用生产凭据恢复它。

## 0.3.0 数据清理（待用户另行授权发布）

已删除模板/继承/覆盖代码及两个页面，保留本店原生分类/标签与商品集合。无单独分类业务表，不应删除 WordPress 核心表或 gsm_catalog/gsm_audit。

未来发布应在维护窗口先完成清理与规则验收，再开放前台；未迁移的规则不会把旧来源编号解释成本店编号。网络管理员普通 admin_init 会按活动店的 gsm_native_categories_version 运行清理（AJAX 跳过）。也可在已授权的维护窗口调用 CategoryMigration::run/store：先把旧集合条件映射成本店编号，持久化 taxonomy_scope=local，再清除三个退休 termmeta，重建集合并记录版本。无法唯一对应的规则停用，需管理员重新选择条件保存；不能直接删源映射而遗漏集合条件转换。

原生 term 的 ID/文字/图片/层级/SEO 元数据、附件、基本商品归属、UUID、客户、订单保留。旧规则现在依据本店实际子树，若原模板层级不同，成员变化属于新语义，应逐集合核对。非活动店重新启用后会补做。失败可重试，不能批量删除真实分类来“清理模板”。

必须备份匹配数据库和代码；退回旧源码不会恢复已删元数据或旧集合条件。完整回滚需要匹配备份，保留备份后增量。**目前不运行任何线上清理或发布。**

## 首次部署的必要顺序

1. 用旧版 `scripts/security-baseline.sh check` 检查现有线上，失败必须调查。
2. 用 `scripts/security-backup.sh` 创建 root 私有完整备份；另备份旧 compose/nginx/脚本/主题/安全清单，记录精确位置。
3. 建立 `security-reports/deployment-in-progress`，使监控暂时避开升级窗口。
4. 上传插件及新只读挂载、允许名单、主题品牌适配和安全脚本。
5. 先以 `WORDPRESS_MULTISITE=0` 使用新版 Compose，保持原主店 URL，运行 `wp core multisite-convert --base=/ --skip-config` 创建网络表。
6. 然后才设置 `.env` 的 `WORDPRESS_MULTISITE=1`、`WORDPRESS_NETWORK_DOMAIN=www.animecats.online`，重建 WordPress 容器并重载 Nginx。
7. 网络启用 WooCommerce 和 Group Shop Manager；保留主店原业务插件和数据。启用共享主题，明确初始化来源测试数据（不得无意重放订单）。
8. 对主店已有用户回填首次来源，对已有测试订单只补归属元数据，不改状态、不发信。
9. 恢复 root 所有权、只读挂载和可写 uploads/cache，授权代码验证后更新 manifest；安全检查、首页、后台、商品、购物车与共享账号验证通过。
10. 移除部署标记，检查监控、更新 HANDOFF / CHANGELOG / 根部署记录。不能以插件激活成功代替多店验收。

Compose 的多站点分支不能再全局 define WP_HOME/WP_SITEURL，否则所有店铺会被钉到主域名。独立域名由原生 blog 表和每站 home/siteurl 决定。Nginx 子目录重写必须只匹配有前缀的路径，并置于 `if (!-e $request_filename)` 内，保留已有 WordPress 文件及目录。否则 `/wp-admin/index.php` 会被误改成 `/index.php` 而自循环，`/wp-admin/admin.php` 会变成 `/admin.php` 返回 404。

每次路由部署必须通过真实 HTTP 请求检查主店及分店的 `/wp-admin/`、`wp-admin/admin.php?page=group-shop-manager`、`wp-admin/network/` 和登录页，跟随重定向且限制最大次数；未登录时应抵达登录页，不能自循环或 404。还应检查登录后管理页、原生静态资源和上传 PHP 阻断。CLI 调用 Admin::render() 只能验证渲染函数，不能代表这些入口可访问。

服务器回归入口：`python3 scripts/group-shop-manager/check-http-routes.py`。从 loopback 上游发送真实 HTTP，默认检查主店，跟随最多 8 次跳转，验证登录表单、核心 CSS 和 PHP 阻断。第二店铺运行 `python3 scripts/group-shop-manager/check-http-routes.py --host test.animecats.online --subsite`；路径式店铺可指定 `--store slug`。传入 `--cookie-file` 时读取 root 私有的短期测试 Cookie 并检查已登录页面，不输出 Cookie；验收后必须撤销该测试会话并删除文件。中央插件管理页属于主店；分店使用原生商品后台，不注册中央菜单。

容器 Nginx 配置是单文件只读挂载。rsync 原子替换宿主文件后，仅执行 `nginx -s reload` 可能仍读取挂载固定的旧 inode；必须 `docker compose up -d --no-deps --force-recreate nginx` 重新挂载，再验证容器内配置和真实 HTTP。这与宿主证书代理 Nginx 的 `nginx -t && nginx -s reload` 是两个不同层级。

## 第二店铺域名（2026-11-03）

第二店铺 blog 2 已从 `/demo-store/` 映射到 `https://test.animecats.online/`。主网络的 DOMAIN_CURRENT_SITE 仍为 www.animecats.online；只更新 blog 2 的 domain/path、home/siteurl，并在该店表内进行序列化安全 URL 替换。编号、UUID、草稿商品 18、禁止收录和关闭邮件保持。不同域名仍分别登录。

宿主 Nginx 配置源码 `nginx/host/test.animecats.online.conf` 对应 `/etc/nginx/conf.d/group-shop-test.conf`，代理同一个 127.0.0.1:8086，以 Host 区分店铺。主店宿主配置包含旧 `/demo-store/` 到新域名的 301。已有其他服务的通配符域名配置未修改。

Let's Encrypt 分店证书安装到 `/root/ssl/group-shop-test/{fullchain.pem,privkey.pem}`，私钥 root-only；acme.sh 使用 `/var/www/acme` 的 HTTP 验证并设置续期安装和 `nginx -t && nginx -s reload`。备份 `/root/group-shop-backups/20261102T175306Z`；变更前宿主配置 `deploy-backups/gsm-store2-domain/`。如撤销域名映射，应同时恢复 blog 2 的域名/路径、home/siteurl、该店媒体链接及主店旧路径规则，不能仅删除新域名 vhost。

## 后续代码部署

### 0.2.2 店铺列表与域名

本次代码升级不修改现有域名，无需执行数据迁移；验证升级前后 domain/path/home/siteurl、UUID、Logo、主题、收录及用户/订单计数。列表和弹窗需经真实浏览器/HTTP 验证，不能只用 CLI 渲染。测试 store-list.php 仅允许隔离 QA，切勿运行到生产。

分店改域名前：备份；配置 DNS 与精确 Nginx server_name 代理到 127.0.0.1:8086、传递 Host/X-Forwarded-Proto；申请/安装证书与续期；再在弹窗保存域名。不要依赖服务器其他服务的通配符配置。新域名根路径和 HTTPS 写入本店原生配置，主网络常量不变。已有内容另外进行本店范围内的序列化安全 URL 迁移（排除历史订单快照/共享数据），为旧地址配置 301，更新第三方回调，验证前台、后台、媒体和登录无循环。插件不自动探测或配置这些基础设施。

回退域名时同时恢复该店 domain/path/home/siteurl、链接迁移、旧新代理和证书安排；回退插件代码不会撤销原生域名修改。主店域名不开放 UI 修改。

### 0.2.1 店铺设置

复用 manager-plugin 部署流程，不需要重跑 0.2.0 无限库存迁移或改域名。现有 Logo 懒读取旧 custom_logo，保存后形成店铺引用；现有 blog_public 值保留，不批量切换收录。QA 用 store-settings.php 验证保存/恢复/原生同步，浏览器还需验证媒体库的当前店归属、上传、选择和移除，不能仅用 CLI 控件渲染代替。

QA 合成数据由 root WP-CLI 生成，新建 uploads 目录可能为 root 所有；浏览器上传测试前恢复 QA uploads 为 33:33。生产仍由原安全基线维护权限，不允许为媒体功能开放代码目录写权限。

跨店媒体只在带独立授权的媒体请求内切换，原生 wp_die 和 shutdown 恢复；该请求退出模型例外见 ARCHITECTURE。永久删除等不在弹窗适配范围，使用所属店原生媒体页。无新账号/权限/SSO，仍只给网络管理员使用。

### 0.2.0 升级

1. 完成隔离集成、浏览器和 HTTP 验证；线上原版本安全检查通过后做 `scripts/security-backup.sh` 全量私有备份。
2. 使用 `scripts/deploy-manager-plugin.sh` 部署插件（包含删除旧 inventory 类），保留已有域名、宿主和容器 Nginx 路由。
3. 上传本项目的 `scripts/group-shop-manager/upgrade-v020.php`，在服务器主店维护 WP-CLI 执行：

```bash
docker compose --profile tools run --rm -T --user 0:0 \
  -e TROVESIA_MAINTENANCE=1 -v "$PWD/scripts/group-shop-manager:/gsm-ops:ro" \
  wpcli --allow-root --user=1 eval-file /gsm-ops/upgrade-v020.php </dev/null
```

脚本严格验证 group-shop 主域名、test 分店及版本，逐店关闭库存管理并经 CRUD 保存现有商品/变体、修正 lookup，再给分店应用分类模板。比对商品名称、售价、发布状态、订单/用户计数和 UUID，检查后台页。请求内阻止邮件，不创建订单、不操作支付。可重复运行，保留分店字段覆盖；不会建立另一个 demo 店。旧 `accept-production.php` 已限制为历史 0.1.0，不能用于新版网络。

4. 使用真实 HTTP 验证主店/test 的未登录与已登录后台、新分类页和商品供货状态。恢复权限、授权文件 manifest、安全门禁、缓存和监控，并更新 HANDOFF 和部署报告。

新版没有额外共享分类表；新增每站 termmeta 和集合 option。无限库存的原生元数据会持久化，停用插件不会恢复旧数量。回退 0.1.0 代码也不会撤销这些数据变更；若需要完整恢复旧库存/分类，须按已验证备份恢复数据库并处理备份后的增量订单。

完成首次迁移后使用 `scripts/deploy-manager-plugin.sh`，或 `scripts/deploy-all.sh` 同时发布主题与三个业务插件。脚本有部署前后安全门禁和组件备份。涉及数据库、Compose、服务器重写和网络设置时不能只部署插件目录。

PHP 容器的 group-shop-manager、Trovesia 代码和安全工具为只读挂载。WP-CLI 执行受控安装使用服务器 root 的维护入口。不得为了安装插件关闭现有基线或将代码目录交给 web 用户写入。

## 回滚

普通插件故障：网络停用 group-shop-manager，再恢复匹配版本代码、主题适配与 manifest；店铺业务数据不会因停用或卸载被删除。

首次 Multisite 迁移故障：恢复迁移前完整数据库、wp-config、.env、Compose、Nginx 和旧代码，再重建受影响容器。仅停用插件不会把 Multisite 变回单站。恢复数据库会覆盖备份后数据；本轮数据已获授权为测试数据，未来有真实订单后必须先处理增量数据，不可直接覆盖。

备份、数据库和凭据留在 root 私有目录；文档只记录路径，不记录具体密钥。
