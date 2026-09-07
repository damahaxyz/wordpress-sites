# WordPress Sites

这个仓库统一管理同一台服务器上的多个独立 WordPress 站点。

## 站点

```text
wordpress-sites/
├── aromamatrix-shop/
│   ├── compose.yaml
│   ├── theme/
│   ├── plugin/
│   ├── nginx/
│   ├── php/
│   └── scripts/
├── 13799-shop/
│   ├── compose.yaml
│   ├── theme/
│   ├── plugin/
│   ├── nginx/
│   ├── php/
│   └── scripts/
├── perfumehouse-shop/
│   ├── compose.yaml
│   ├── theme/
│   ├── plugin/
│   ├── nginx/
│   ├── php/
│   └── scripts/
└── trovesia-shop/
    ├── compose.yaml
    ├── theme/
    ├── plugin/
    ├── nginx/
    ├── php/
    └── scripts/
```

`aromamatrix-shop` 是 `shop.aromamatrix.com` 的独立 WordPress、MariaDB 与
Redis 部署。新增站点时应使用新的子目录、Compose 项目名、宿主机端口、
数据库凭据、数据卷与备份目录。

`13799-shop` 是第二个独立 WordPress 商店。默认仅在宿主机本地监听
`127.0.0.1:8081`，MariaDB 通过 `127.0.0.1:3307` 供 SSH Tunnel 使用。

`perfumehouse-shop` 是第三个独立 WordPress 商店，包含专属主题与插件。
默认监听 `127.0.0.1:8082`，MariaDB 通过 `127.0.0.1:3308` 供 SSH Tunnel
使用；正式域名为 `https://www.perfumehouse.vip`。

`trovesia-shop` 是第四个独立 WordPress 商店，包含 Trovesia 专属主题与插件。
默认监听 `127.0.0.1:8083`，MariaDB 通过 `127.0.0.1:3309` 供 SSH Tunnel
使用；正式域名为 `https://www.trovesia.com`。

## AROMAMATRIX Shop

```bash
cd aromamatrix-shop
docker compose config --quiet
docker compose ps
```

主题和插件部署：

```bash
cd aromamatrix-shop
./scripts/deploy-all.sh
```

详细说明见 [aromamatrix-shop/README.md](aromamatrix-shop/README.md)、
[aromamatrix-shop/DEVELOPMENT.md](aromamatrix-shop/DEVELOPMENT.md) 和
[aromamatrix-shop/DEPLOYMENT.md](aromamatrix-shop/DEPLOYMENT.md)。

## 13799 Shop

```bash
cd 13799-shop
cp .env.example .env
# 替换 .env 中的两个数据库密码
docker compose config --quiet
docker compose up -d --wait
```

详细说明见 [13799-shop/README.md](13799-shop/README.md)。

## PerfumeHouse Shop

```bash
cd perfumehouse-shop
cp .env.example .env
# 替换 .env 中的两个数据库密码
docker compose config --quiet
docker compose up -d --wait
```

详细说明见 [perfumehouse-shop/README.md](perfumehouse-shop/README.md)。

## Trovesia Shop

```bash
cd trovesia-shop
cp .env.example .env
# 替换 .env 中的两个数据库密码
docker compose config --quiet
docker compose up -d --wait
```

详细说明见 [trovesia-shop/README.md](trovesia-shop/README.md)。
