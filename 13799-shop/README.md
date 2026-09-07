# WordPress + Nginx + MariaDB

這是一套適合部署在單台 VPS 的 Docker Compose 設定：

- Nginx 對外提供 HTTP
- WordPress 使用 PHP-FPM
- MariaDB 對主機僅綁定 `127.0.0.1`，不公開資料庫連接埠
- WordPress 檔案與資料庫分別保存在 Docker named volumes
- 自研 AROMAMATRIX 主題與外掛以 Git 管理並掛載進容器

## 自研主題與外掛

```text
theme/aromamatrix/
plugin/aromamatrix-plugin/
```

本地初始化與一鍵部署方式請見：

```text
DEVELOPMENT.md
```

常用發布命令：

```bash
./scripts/deploy-theme.sh
./scripts/deploy-plugin.sh
./scripts/deploy-all.sh
```

## 啟動

需求：Docker Engine 與 Docker Compose v2。

```bash
cp .env.example .env
```

編輯 `.env`，至少替換以下兩個密碼：

```dotenv
MARIADB_PASSWORD=請換成高強度隨機密碼
MARIADB_ROOT_PASSWORD=請換成另一組高強度隨機密碼
```

檢查設定並啟動：

```bash
docker compose config
docker compose pull
docker compose up -d
docker compose ps
```

預設開啟：

```text
http://伺服器IP:8080
```

第一次進入時，依 WordPress 安裝畫面建立網站管理員。請不要使用 `admin` 作為管理員名稱。

## 常用操作

查看日誌：

```bash
docker compose logs -f --tail=100
```

停止服務但保留資料：

```bash
docker compose down
```

重新啟動：

```bash
docker compose up -d
```

> 請勿執行 `docker compose down -v`，這會刪除 WordPress 與資料庫 volumes。

## 使用 DBeaver + SSH Tunnel

MariaDB 在伺服器上僅監聽：

```text
127.0.0.1:3306
```

請勿把它改成 `0.0.0.0:3306`，也不需要在防火牆開放 3306。

在 DBeaver 建立 MariaDB 連線：

```text
Main
  Host: 127.0.0.1
  Port: 3306
  Database: wordpress
  Username: wordpress
  Password: .env 中的 MARIADB_PASSWORD

SSH
  Host/IP: VPS 的 IP 或網域
  Port: 22
  User name: VPS 的 Linux 使用者
  Authentication: Public Key 或 SSH Agent
```

如果伺服器的 3306 已被其他服務占用，可修改 `.env`：

```dotenv
MARIADB_HOST_PORT=3307
```

此時 DBeaver 的資料庫 Port 也要改成 `3307`。

## 備份

備份必須同時包含：

1. MariaDB 資料庫
2. `wp-content` 中的上傳檔案、外掛與佈景主題
3. Compose、Nginx、PHP 設定與備份腳本（可提交 Git）
4. `.env`（只保存在密碼管理器或加密備份中）

執行完整備份：

```bash
./scripts/backup.sh
```

腳本會在 `backups/` 產生：

```text
database-時間.sql.gz
wp-content-時間.tar.gz
checksums-時間.sha256
```

備份先寫入暫存檔，完成壓縮檔驗證後才會改成正式檔名。

### 排程

例如每天凌晨 03:15 備份：

```cron
15 3 * * * cd /opt/wordpress-sites/13799-shop && ./scripts/backup.sh >> /var/log/wordpress-backup.log 2>&1
```

請把 `/opt/wordpress-sites/13799-shop` 換成伺服器上的實際專案路徑。

### 異地備份

本機 `backups/` 只能防止操作失誤，無法防止整台 VPS 損壞或遭入侵。建議使用 restic 將它加密備份到 S3 相容物件儲存、Backblaze B2 或另一台伺服器。

建議保留策略：

```text
每日備份保留 7 份
每週備份保留 4 份
每月備份保留 6 份
```

至少每月實際測試一次還原，不要只確認備份檔案存在。

### 還原資料庫

還原會改寫資料，執行前先建立一份當下備份：

```bash
gzip -dc backups/database-時間.sql.gz | \
  docker compose exec -T db sh -ec \
  'exec mariadb --user=root --password="$MARIADB_ROOT_PASSWORD"'
```

還原 `wp-content`：

```bash
docker compose exec -T wordpress \
  tar -C /var/www/html -xzf - < backups/wp-content-時間.tar.gz
```

### GitHub

可以提交 GitHub：

- `compose.yaml`
- `nginx/`、`php/`
- `scripts/`
- 自行開發的佈景主題或外掛原始碼

不要提交 GitHub，即使是 private repository：

- `.env`、密碼、SSH Key
- 資料庫 `.sql` 或 `.sql.gz`
- `wp-content/uploads`
- 完整備份壓縮檔

Git 會永久累積每一版大型備份，而且資料庫可能包含使用者信箱、密碼雜湊、工作階段、訂單或其他個人資料。`.gitignore` 已排除這些檔案，但提交前仍應檢查：

```bash
git status
```

## HTTPS

正式網站使用：

```text
https://www.13799.com
```

主機 Nginx 配置來源：

```text
nginx/host/www.13799.com.conf
```

伺服器上的配置與憑證位置：

```text
/etc/nginx/sites-available/www.13799.com
/etc/nginx/sites-enabled/www.13799.com
/etc/nginx/ssl/www.13799.com.pem
/etc/nginx/ssl/www.13799.com.key
```

Cloudflare SSL/TLS 模式應使用 `Full (strict)`。Origin Certificate 與私鑰只保存在本機 `cert/` 和伺服器 `/etc/nginx/ssl/`，整個 `cert/` 已被 Git 忽略。

WordPress 容器仍只監聽 `127.0.0.1:8080`，由主機 Nginx 負責 HTTPS 與反向代理。
