# Group Shop Manager 0.2.1：店铺设置交付

日期：2026-11-03（Asia/Taipei）。**已上线并网络启用。** 本轮只实施店铺设置的 Logo 与搜索收录功能，其他原生后台重构仍为规划。

## 使用结果

- 店铺设置不再要求填写“Logo 附件编号”。使用原生媒体弹窗，从当前店媒体库选择或上传图片，支持预览、更换、移除；最后保存店铺设置。
- Logo 是每店 gsm_logo_id 引用，回退兼容旧 custom_logo；原生 Logo 编辑与输出同源，更换主题保留。没有 Logo 时使用主题的店名回退。
- “搜索引擎收录”两项：不鼓励收录（建站中）、允许收录。直接使用原生 blog_public，与阅读设置/Multisite 可见性同步，修改后清缓存。新店默认不鼓励。
- 收录开关是搜索引擎建议和原生 noindex，不是访问控制，不保证搜索引擎立即收录或移除。
- 线上升级前后 Logo、主题、收录状态及用户/订单计数严格比对一致：[before](gsm-v021-before.json)、[after](gsm-v021-after.json)。没有替用户切换可见性或替换实际 Logo。

## 验证

- 原有 **120 项**集成通过：[qa-regression-v021.json](qa-regression-v021.json)。
- 新增 **19 项**店铺设置验证通过：[qa-store-settings-v021.json](qa-store-settings-v021.json)，覆盖 Logo 保存、主题切换、原生修改/移除、收录读写与 robots、店铺隔离、非法值/权限/nonce 拒绝。
- 浏览器真实验证：分店媒体库没有混入主店合成图片；选择、预览、保存、移除成功；原生上传的文件位于 sites/20 路径（仅 QA）。新图上传通过真实文件选择器完成，无生产图片上传。
- 处理过 QA root CLI 创建 uploads 目录导致的 web 写入权限问题，恢复 QA 目录 33:33 后上传复验成功。生产原安全基线不变。
- 发现一次分店媒体初始化跳转，修正为 admin_init 只验证授权，直到原生媒体 action 前才切换店铺；async-upload 在初始化末尾切换。加入 QA 原生初始化上下文守卫，并在守卫生效后复验媒体查询；修正后的原生上传也验证成功。
- 线上真实 HTTP **55 项**通过：媒体/授权/操作范围 8 项，全部后台和数据状态 15 项，两个域名未登录/已登录路由各 8 项，共 32 项。证据为本目录 gsm-v021-{store-http,admin-http,main-http,test-http,main-auth,test-auth}.json。
- 媒体请求仍使用 WordPress 原生查询/上传/修改处理器；独立店铺 nonce、网络管理员和操作白名单控制归属。不支持的永久删除直接拒绝，不会回落到主店附件编号。
- 验证用短期管理员会话已单独撤销，私有临时文件删除；原密码、原有会话未清除，未新增生产用户/订单，未实际发送邮件或收款。
- 最终文档/代码同步并恢复权限后，安全完整性检查通过：`/root/wordpress-sites/group-shop/security-reports/check-20261103T045416Z-2022867.log`。
- 主店与 test 分店公网首页均 HTTP 200，监控 healthy=True，部署标记移除。QA 容器已停止，测试页与 SSH 隧道关闭，卷保留。

## 备份及交付

- 升级前完整私有备份 `/root/group-shop-backups/20261103T043415Z`，归档与 SHA-256 校验通过。
- 旧插件备份 `deploy-backups/group-shop-manager-20261103T043802Z.tar.gz`；发布后小修正前确认待封存差异仅为已授权文档，没有其他线上代码变更。
- 插件包 `dist/group-shop-manager-0.2.1.zip`，23 个文件，包含插件和维护文档，不包含数据库、会话、SMTP 凭据或 QA 数据。
- SHA-256：`a6e0382fd682ebfd8eb63e7c36b4101c542e5c84f447010aacb11fa3f3fc5e4f`。
- 不需要重跑 0.2.0 库存迁移或变更域名。权限、只读代码挂载、现有收款范围和无限库存策略保持。
- 原生 JSON 会通过 wp_die 退出，媒体请求在原生 die handler 前恢复店铺、shutdown 兜底；其他跨店操作仍使用 finally，详见 ARCHITECTURE。

新对话阅读插件 HANDOFF / USER-GUIDE / ARCHITECTURE；后续按用户要求逐项确认与开发，不把完整原生后台重构规划标记为已实现。
