# WordPress Sites

这个仓库统一管理同一台服务器上的多个独立 WordPress 站点。

## 站点

```text
wordpress-sites/
├── 13799-shop/
│   ├── compose.yaml
│   ├── theme/
│   ├── plugin/
│   ├── nginx/
│   ├── php/
│   └── scripts/
├── 13799-shop-old/
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
├── trovesia-shop/
│   ├── compose.yaml
│   ├── theme/
│   ├── plugin/
│   ├── nginx/
│   ├── php/
│   └── scripts/
├── manga-shop/
    ├── compose.yaml
    ├── theme/
    ├── plugin/
    ├── nginx/
    ├── php/
    └── scripts/
└── tikool-shop/
    ├── compose.yaml
    ├── theme/
    ├── plugin/
    ├── nginx/
    ├── php/
    └── scripts/
```

`13799-shop` 是当前 `www.13799.com` 的 WordPress、MariaDB 与 Redis 部署，
默认使用宿主机端口 `8080` / `3306`。为保持现有数据卷与容器连续性，
Compose 项目名继续使用 `wordpress`。

`13799-shop-old` 是保留的旧版独立 WordPress 商店。默认仅在宿主机本地监听
`127.0.0.1:8081`，MariaDB 通过 `127.0.0.1:3307` 供 SSH Tunnel 使用。

`perfumehouse-shop` 是第三个独立 WordPress 商店，包含专属主题与插件。
默认监听 `127.0.0.1:8082`，MariaDB 通过 `127.0.0.1:3308` 供 SSH Tunnel
使用；正式域名为 `https://www.perfumehouse.vip`。

`trovesia-shop` 是第四个独立 WordPress 商店，包含 Trovesia 专属主题与插件。
默认监听 `127.0.0.1:8083`，MariaDB 通过 `127.0.0.1:3309` 供 SSH Tunnel
使用；正式域名为 `https://www.trovesia.com`。

`manga-shop` 是用于迁移 `www.mangaproduct.com` 的第五个独立 WordPress 商店，
使用不依赖 Elementor 的自定义主题和独立业务插件。默认监听
`127.0.0.1:8084`，MariaDB 通过 `127.0.0.1:3310` 供 SSH Tunnel 使用。

`tikool-shop` 是用于宠物定制产品的独立 WordPress 商店，包含专属主题、插件和
订单级宠物照片上传。默认监听 `127.0.0.1:8085`，MariaDB 通过
`127.0.0.1:3311` 供 SSH Tunnel 使用。

## 13799 Shop

```bash
cd 13799-shop
docker compose config --quiet
docker compose ps
```

主题和插件部署：

```bash
cd 13799-shop
./scripts/deploy-all.sh
```

详细说明见 [13799-shop/README.md](13799-shop/README.md)、
[13799-shop/DEVELOPMENT.md](13799-shop/DEVELOPMENT.md) 和
[13799-shop/DEPLOYMENT.md](13799-shop/DEPLOYMENT.md)。

## 13799 Shop Old

```bash
cd 13799-shop-old
cp .env.example .env
# 替换 .env 中的两个数据库密码
docker compose config --quiet
docker compose up -d --wait
```

详细说明见 [13799-shop-old/README.md](13799-shop-old/README.md)。

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

## Manga Shop

```bash
cd manga-shop
cp .env.example .env
# 替换 .env 中的两个数据库密码
docker compose config --quiet
docker compose up -d --wait
```

详细说明见 [manga-shop/README.md](manga-shop/README.md)。

## Tikool Shop

```bash
cd tikool-shop
cp .env.example .env
# 替换 .env 中的两个数据库密码
docker compose config --quiet
docker compose up -d --wait
```

详细说明见 [tikool-shop/README.md](tikool-shop/README.md)。
