# 本地插件更名与渠道禁用

2026-11-06，Asia/Taipei。仅Group Shop本地七店，未连接或部署生产。

| 原插件 | 当前目录／入口／显示名 | 本地状态 |
|---|---|---|
| group-shop-manager | plugin/muti-storefront-plugin/muti-storefront-plugin.php；muti-storefront-plugin 0.5.10 | 已启用 |
| trovesia-plugin | plugin/product-addon-plugin/product-addon-plugin.php；product-addon-plugin 3.8.5 | 已启用 |
| trovesia-channel | 原源码目录保留 | 禁用；当前QA原本即未启用／未挂载 |

两个新插件翻译域同新slug。保留GroupShop／GSM、Trovesia／TROVESIA内部PHP接口、数据键、hooks、短代码和动态区块；没有重新激活初始化、导入或重置业务。QA项目、私有目录及脚本／测试分组继续沿用group-shop-manager命名，不新建数据库卷。

新后台入口为admin.php?page=muti-storefront-plugin，旧page=group-shop-manager以同权限隐藏兼容页保留，GET安全跳转并保留tab／店铺参数。原生核心在admin_init前检查页面权限，因此已注册隐藏兼容入口，避免旧书签先被403拒绝。旧直接POST返回410，原admin-post处理器保持nonce和权限校验。

默认主题页脚只在Trovesia Channel实际加载时显示渠道登录入口；七店均已确认隐藏。未删除渠道源码、角色、配置或业务表，也未修改独立trovesia-shop项目。

## 验证

- [runtime.json](runtime.json)：27项通过，包括两个新插件真实启用／头信息／翻译域、旧入口不存在、兼容PHP API、渠道未加载、业务资料核对和45个插件PHP文件语法。
- [http.json](http.json)：43项通过，覆盖七店首页／原商品数／新插件资源、渠道入口隐藏、实际资源200、跨店商品及公共档案404、分类统计、72条主店产品Sitemap、原生后台登录、新后台页面／资源、旧书签保留tab跳转、文档入口与原生插件列表。
- 页脚PHP、维护脚本、Compose配置和git diff --check已验证，结果见[configuration.json](configuration.json)。

最初检查发现附加插件头中的旧Text Domain及后台旧书签的提前权限检查，均修复后重新运行，报告只计最终成功结果。测试用当前本地管理员的已有凭据，未输出密码／nonce／Cookie，测试会话已退出，用户其他会话保留。

## 数据核对

改名前私有SQL、两个源码包与校验快照保存在/private/tmp/group-shop-manager-qa/.identity-private/rename-v0510/，不提交或公开。切换期间短暂停止本地Web服务，以--skip-plugins --skip-themes的受控WP-CLI切换active_plugins及必要插件路径元数据，再按新挂载重建wordpress/nginx，保留数据库与数据卷。

posts、postmeta、terms、termmeta、term_taxonomy、term_relationships、comments、commentmeta、users九表原始行哈希一致；七品牌注册及业务bank保持。用户元数据与SQL备份逐项核对，仅原生后台测试登录正常更新了管理员的wc_last_active活动日期，资料、角色、密码和原会话均保留，未通过恢复旧活动日期来伪造完全相同。仍72公共＋72主店销售父商品，没有生成页面或营销资料。

生产仍沿用其历史状态，未核实渠道是否启用；未来发布需另获明确授权并成套切换挂载、启用记录、缓存与回滚，不能把本地更名当作已上线。
