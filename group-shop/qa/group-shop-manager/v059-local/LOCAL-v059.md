# 0.5.9 本地 SEO 隔离验收

2026-11-06，Asia/Taipei。仅本机七个 HTTP `.local` 域名，WordPress 7.0.4、PHP 8.3、WooCommerce 11.1.2。未连接或发布生产，CSS／JS／图片及其他 wp-content 素材仍共用。

## 已修复的入口

- 显式商品／公共档案／页面 ID 在原生查询前校验，非法请求404；停止404地址猜测，防止数字ID先跳到同名地址。原生有效本店旧文章slug仍301，外店旧slug不跳。
- Woo `ProductsBySlug`直接使用`get_page_by_path`或SQL且只看`is_publicly_viewable()`，原有列表过滤不覆盖这个入口。新增当前店销售版本定位及原生ID处理器转交，覆盖公开slug、内部slug与变体，公共档案／外店记录不返回。
- 私有集合的单条分类API、显式include（对象／ID／数量）、归档和通用taxonomy查询验证所属店及规则有效性；普通分类／标签继续共用资料。
- 分类API商品数和评论数按店计算，不被Woo全局count／meta统计覆盖。`rest_request_after_callbacks`覆盖内部REST子请求；分类／属性聚合缓存的输入显式带品牌。
- 原生品牌条件以AND包住既有OR查询；公开路径同时验证资源类型。商品／页面／文章Sitemap及Feed使用受限原生查询，分类Sitemap只列本店有商品的词条及共享祖先，作者Sitemap关闭并404。
- 菜单归属沿用NativeMenus；原生菜单指定无效外店ID时可能回退为本店另一个菜单，不能渲染外店菜单。正文／摘要／短描述的已注册外店业务a.href／iframe.src在渲染时移除，外店oEmbed不解析；保存内容不重写，素材不隔离。
- 单条评论、原生Woo商品和父子变体关系补归属校验；普通后台／受信unscoped访问不受公开边界阻断。

## 结果与证据

| 范围 | 实际结果 | 证据 |
|---|---|---|
| 运行机制／授权／原生REST／跨品牌缓存 | 46项通过 | [runtime.json](runtime.json) |
| 实际公开HTTP与SEO结构 | 141次请求，315项通过，0失败 | [http-seo-isolation.json](http-seo-isolation.json) |
| 清理后真实业务基线 | 19项通过 | [post-cleanup-health.json](post-cleanup-health.json) |
| PHP | 插件及本轮脚本35文件语法通过 | [syntax-php.json](syntax-php.json) |
| Python与diff格式 | 语法与git diff --check通过 | [syntax-host.json](syntax-host.json) |
| 合成资料与原数据 | 已清理并核对保留 | [cleanup.json](cleanup.json) |

HTTP涵盖A／B同公开路径分别解析自己的商品／页面、正常产品及变体、公共与外店数字ID／内部slug、商品／页面／文章／评论REST、共享分类与私有集合列表及详情、商品／评论数量、价格区间／库存／分类聚合、跨域缓存预热、Sitemap及其域名与数量、canonical、JSON-LD业务URL、菜单／正文业务链接、oEmbed、搜索与Feed、同店及跨店旧slug、七域商品链接，以及伪造未签名品牌请求头。收录在fixture期间只对A／B暂时开启；清理后已恢复。

原生WordPress和Store API批量接口均不支持GET子请求，HTTP测试确认400拒绝，不把其写成“GET批量调用通过”。早期失败项包含测试预期与实际接口协议差异，以及开发中的回调接管／类型兼容问题，均修正后重新执行；本报告只统计最终成功运行。没有以命令退出码代替断言结果。

## 数据保留与复验

开始时144个父商品：72公共档案＋72主店版本，A／B无业务商品。完整测试前SQL备份与八表／七品牌bank原始快照仅在私有QA目录，不提交或展示。测试新建并最终清理24条商品／变体／页面／文章／菜单项、7词条及3评论；清理器逐对象校验随机前缀、归属、清单ID及订单引用，并检查并发业务配置差异。

原posts、postmeta、terms、termmeta、term_taxonomy、term_relationships、comments、commentmeta逐行与开始快照一致；七品牌注册和业务配置核对恢复，保留原菜单、索引、集合配置及后台身份，仍144父商品。清理后七店商品API数量及共享分类计数均正确：主店72、其他店0；主店Sitemap仍72条，A索引设置恢复，跨店GET／HEAD与公共ID均404。

复验顺序见插件[OPERATIONS](../../../plugin/group-shop-manager/docs/OPERATIONS.md)：fresh `seo-isolation-v059.php` → `check-seo-isolation-v059.php` → 从私有QA目录运行 `check-seo-isolation-v059-local.py` → `cleanup-seo-isolation-v059.php`。禁止复用旧清空／迁移fixture编号。私有fixture在成功清理后删除；再次验收必须新建。

范围是当前原生WordPress／WooCommerce及本项目主题，不是对未知SEO插件、自定义SQL／接口、任意用户脚本或未来Woo版本的兼容承诺。普通分类词条、图片与静态资源仍共用；noindex不是资源授权机制。上线仍须用户新的明确发布通知及生产实际环境验收。
