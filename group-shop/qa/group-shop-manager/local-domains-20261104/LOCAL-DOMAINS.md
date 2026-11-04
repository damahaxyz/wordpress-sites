# 本地域名迁移验收 — 2026-11-04

用户最初提供 .com，后明确改用 .local，以沿用本机现有 `*.local` 代理例外。最终主店 www.main.local，分店 branda／brandb／brandc／brande／brandf／brandg（均加 www. 与 .local），全部 HTTP、无需端口。没有改 Shadowrocket／系统代理，没有发布线上。当前插件源码保持 0.3.4，本次没有改业务实现。

blog_id 1／6／7／9 保留原数据；9 即原 QA Shared Store，店名保持。11／12／13 为新建 E／F／G 空白测试店。原生网络／店铺 URL、媒体 upload_url_path 与选定内容的序列化链接已迁移，附件编号／文件路径和 GUID 不变，历史订单快照不替换。

- http.json：41 项真实 HTTP 检查通过。七店前台、各自登录与 Cookie、中央列表、按店授权媒体查询、媒体本店域名及图片实际访问、共享分类主站入口。
- migration.json：迁移前后 UUID、产品／变体状态价格与图片引用、集合定义／分类关系、订单数量、共享用户和发布映射保持。
- state.json：登录与媒体验收后再次核对七店业务数据／原生地址／媒体根目录，全部通过；QA 邮件拦截仍加载。
- Apache／Nginx 配置语法通过；七域名在宿主解析为 127.0.0.1、容器内解析为 QA Nginx。内部 HTTP 登录页通过。
- localhost:18087 根地址 302 至 www.main.local；宿主原默认站仍从 127.0.0.1 跳转 www.tikool.com，其他本地项目未重启。
- 内置浏览器实际登录 www.main.local 并看到七店新域名；stores-local.jpg 为最终概览截图。没有将历史订单功能的完整浏览器验收计入本轮。

数据／原配置私有备份：/private/tmp/group-shop-manager-qa/backups/domains-20261104T040254Z/。原 localhost 四店快照为 database.sql，七店 .com 中间快照为 database-before-local.sql；不复制私有 SQL／凭据到仓库。具体启动／回退见插件 docs/OPERATIONS.md“当前本地域名”。旧 localhost 验收保护保留，未重跑旧业务集成套件。
