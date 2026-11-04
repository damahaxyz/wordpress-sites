# 后台重定向修复与第二店铺域名验收

日期：2026-11-03（Asia/Taipei）。插件 PHP 版本仍为 0.1.0。

## 当前结果

- 主店 <https://www.animecats.online/>；统一管理入口 <https://www.animecats.online/wp-admin/admin.php?page=group-shop-manager>。
- 第二店铺 blog 2 已原位映射到 <https://test.animecats.online/>；前台、账户页、登录和原生后台可用。
- 原 <https://www.animecats.online/demo-store/> 301 到新域名，余下路径与查询参数保留。实际检查 `/demo-store/wp-admin/?gsm-domain-check=1` 的 Location 为 `https://test.animecats.online/wp-admin/?gsm-domain-check=1`。
- 店铺编号、UUID、草稿商品 18、禁止收录和关闭邮件保留。主店商品和订单未迁移到分店；只在 wp_2_ 表替换了 3 处旧媒体链接。
- 共享账户密码不变；不同域名仍需分别登录，未引入 SSO。

## 根因与修复

原 Nginx 子站规则将真实存在的 `/wp-admin/index.php` 当作“子站前缀 + PHP”改写成 `/index.php`，造成 WordPress 对 `/wp-admin/` 自循环；`/wp-admin/admin.php` 被改写后返回 404。将子站重写置于 `if (!-e $request_filename)` 内，保护原生文件和目录。生产与独立 QA 配置同步修复。

Nginx 单文件 bind mount 固定旧 inode，rsync 原子替换后单独 reload 未读取新文件；通过重建 **group-shop nginx 容器**更新挂载使修复生效。宿主代理使用 `nginx -t && nginx -s reload` 加载新域名。未重启、修改其他站点服务。

此前 22 项线上验收采用 CLI 渲染，未覆盖真实后台 HTTP；前台 200 不能证明后台正常。新增真实 HTTP 回归脚本 `scripts/group-shop-manager/check-http-routes.py`。

## 实际验证

- 修复后、域名映射前：主店与子目录分店共 16 项未登录、16 项已登录 HTTP 检查通过。
- 域名映射后最终验收：主店未登录 8 项、已登录 8 项；test 域名未登录 8 项、已登录 8 项，合计 **32 项通过**。覆盖后台首页、index.php、中央管理／分店商品页、网络后台、登录、核心 CSS 与直接 PHP 阻断。
- 已登录检查使用原管理员的短时原生测试会话，不修改密码；完成后只撤销测试会话并删除 root 私有临时文件，未清除用户原有会话。手工附带测试 Cookie 的 HTTP 检查不能代表浏览器跨域自动登录。
- 公网浏览器复核：www 与裸域名后台均抵达主店登录页；test 域名后台抵达 Group Shop Demo 登录页，test 首页标题为 Group Shop Demo。
- 公网主店首页、新分店首页、分店账户页及旧路径跳转均成功。
- 源站分别按 www/test 域名验证 HTTPS 主机名和证书链成功。
- 最后权限与 manifest 更新后完整安全基线退出 0：`security-reports/check-20261102T175712Z-1131645.log`。
- 部署标记已移除，四个线上容器 healthy；监控单次检查 `healthy=True full_check=run`。独立 QA 容器保持停止。
- 原始证据：`security-reports/gsm-main-domain-routes-20261103.json`、`gsm-test-domain-routes-20261103.json`、`gsm-main-domain-authenticated-20261103.json`、`gsm-test-domain-authenticated-20261103.json`；本地同名副本存于本目录。

## 运维与回滚

- 域名变更前完整备份：`/root/group-shop-backups/20261102T175306Z`，归档及 SHA-256 校验通过。
- 原后台路由配置：`deploy-backups/gsm-admin-route-fix/default.conf`。该配置包含已知故障，不应恢复到正常线上。
- 域名变更前宿主配置：`deploy-backups/gsm-store2-domain/`。
- 新域名宿主配置：`/etc/nginx/conf.d/group-shop-test.conf`，源码 `nginx/host/test.animecats.online.conf`。
- Let's Encrypt 证书：`/root/ssl/group-shop-test/{fullchain.pem,privkey.pem}`，有效期至 2027-01-31；私钥 root-only，acme.sh HTTP 验证、安装与续期 reload 已配置。主店证书保留。
- 末次检查发现既有 acme.sh 没有续期 cron，已执行 `--install-cronjob` 补装 root 定时任务：`55 22 * * *`（服务器时区），运行 acme.sh --cron。现有其他 cron 项保留。不能仅以证书签发成功推断自动续期已安装。
- 撤销域名映射须同步恢复 blog 2 的 domain/path、home/siteurl、该店内容链接与主店旧路径规则，不能仅删除 vhost。主网络 DOMAIN_CURRENT_SITE 始终保持 www.animecats.online。
- 文档已更新：HANDOFF、OPERATIONS、USER-GUIDE、CHANGELOG 和根 DEPLOYMENT。当前插件 ZIP 同步更新说明，SHA-256 为 `3793050742202e2be18cc6e4247a6d88fe32fb3345b01b228f92f13c75d3e160`。服务器配置仍需独立部署，ZIP 不包含宿主 Nginx 配置。
