# 0.5.7 本地店铺菜单验收

2026-11-05，Asia/Taipei。仅 Docker 本地与已验证为 loopback 的 HTTP .local 主机；没有连接或发布生产。WordPress 单站、七品牌；保留本地邮件／付款拦截。

- `runtime.json`：26 项运行检查通过。菜单查询／直接 ID、本站页面及销售商品、共享分类／本站集合、本站自定义链接、位置读写、页面自动添加与员工权限。
- `http-native-menus.json`：36 项真实 HTTP 检查通过。原生列表／资源异步搜索、按 ID 选择、跨店／公共资源添加拒绝、本站商品添加、保存与父项重载、非法混合请求不改名、签名、位置、菜单创建／删除、前台 A／B 菜单差异和员工权限。
- 浏览器实际添加页面并 Save Menu，观察 updated 成功；展开 Products 面板、切换 A → B，并查看 B 的独立 Manage Locations。`native-menus-store-a.png` 为保存后的商品／层级／位置，`native-menus-store-selector.png` 为清理后店铺切换界面。
- `syntax.json`：58 个 PHP 文件（插件、主题及本轮两个 PHP 验收脚本）、新 JS／Python 语法与 git diff --check 通过。
- `cleanup.json`：移除本轮 14 条合成 post（含商品、页面和菜单项），追踪的 7 个 term 含已通过原生删除的测试菜单；既有菜单、页面、业务 option bank 和七品牌快照一致。集合重建时间及页面缓存代际等运行值保留最新值，不恢复过期缓存。最初清理保护因集合重建时间变化而停止；确认该键仅为运行时间后排除，最终清理成功，私有 fixture 已移除。
- `original-data.json`：清理后原始商品／订单／页面／共享词表等 718 项检查通过。未创建用户，原菜单／页面内容未迁移。

实现：外观 → 菜单选择店铺，默认主店。商品为本站已发布销售版本；页面／文章为本站已发布资料；普通分类／标签继续共享，商品集合只显示本站启用且未回收项目。支持原生排序／父子层级、位置与按 owner 自动添加页面。Footer menu 有绑定时替换 Customer care 栏，未绑定保留原页脚链接。新店不复制主店菜单位置或 auto_add。

限制：经典原生菜单编辑器是受支持入口，Customizer 菜单组件和相关 REST 编辑器关闭；未知第三方菜单插件未验收。原生菜单名称全站唯一，建议包含店铺名称。自定义链接仅本站域名、根相对路径或锚点。内部受信 PHP／数据库管理者不属于外部表单授权边界。未对生产做兼容性或上线结论。

复验顺序：`native-menus-v057.php` → `check-native-menus-v057-local.py` → 浏览器 → `cleanup-native-menus-v057.php` → `single-brand-data.php`。使用 OPERATIONS 的本地 compose、私有 fixture 挂载和 gsm-admin；PHP fixture 存在时禁止重复初始化，失败先按清单清理。HTTP 脚本记录其创建的 orphan，清理也收集测试菜单内的实际项，禁止泛删历史 QA 资料。
