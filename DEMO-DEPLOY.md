# Waffy Demo Store — Cloud Deployment Runbook (for DevOps)

Goal: a **public, cloud-hosted Magento Open Source 2.4.x** demo store with the  
**Waffy Escrow Payment** module installed, running against the Waffy **sandbox**.

---

## 1. Server requirements


|           | Minimum for a demo                                                                                                                |
| --------- | --------------------------------------------------------------------------------------------------------------------------------- |
| CPU / RAM | 2 vCPU / **8 GB** (Composer + `di:compile` need ≥2 GB free)                                                                       |
| Disk      | 20 GB SSD                                                                                                                         |
| OS        | Ubuntu 22.04/24.04                                                                                                                |
| PHP       | **8.3** (with `bcmath curl dom gd intl mbstring openssl pdo_mysql soap xsl zip sockets`)                                          |
| DB        | MySQL 8.0 **or** MariaDB 10.6                                                                                                     |
| Search    | **OpenSearch 2.x** (required by 2.4.x)                                                                                            |
| Web       | Nginx + PHP-FPM                                                                                                                   |
| TLS       | **HTTPS on a real domain** (Let's Encrypt) — required: the payment page redirect and the Waffy webhook won't work over plain HTTP |
| Other     | Composer 2, Redis (optional, recommended for cache/session)                                                                       |


Point a domain at the box, e.g. `demo.waffyapp.com`, before installing.

## 2. Install Magento (pick ONE)

**A. Managed host (fastest — Cloudways / Nexcess / Adobe Commerce Cloud):**
provision a Magento-ready server from the panel, attach the domain + SSL, then
open SSH and go to section 3.

**B. Self-managed VPS — vanilla install:**

```bash
# from an empty web root, as the web user
composer create-project --repository-url=https://repo.magento.com/ \
  magento/project-community-edition:2.4.9 .
# (needs free Magento Marketplace access keys as the composer auth for repo.magento.com)

bin/magento setup:install \
  --base-url=https://demo.waffyapp.com/ \
  --db-host=localhost --db-name=magento --db-user=magento --db-password=*** \
  --admin-firstname=Admin --admin-lastname=User \
  --admin-email=you@waffyapp.com --admin-user=admin --admin-password=*** \
  --language=en_US --currency=SAR --timezone=Asia/Riyadh \
  --search-engine=opensearch --opensearch-host=localhost --opensearch-port=9200 \
  --use-rewrites=1
```

Optionally load sample data for a fuller demo: `bin/magento sampledata:deploy` then
`bin/magento setup:upgrade`.

**C. Self-managed VPS — Docker:** use a production-oriented stack
(`markshust/docker-magento` in prod mode, or Warden). Do **not** use DDEV — it's
local-dev only.

## 3. Install the Waffy module (same for all options)

```bash
composer require waffy/module-payment          # from Packagist, no access keys
bin/magento module:enable Waffy_Payment
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento setup:static-content:deploy -f      # production mode only
bin/magento cache:flush
bin/magento module:status Waffy_Payment          # → "Module is enabled"
```



## 4. Set up Waffy (Admin → Stores → Waffy setup)

1. Step 1: choose **Sandbox** and create a client for the store in
   `portal-dev.waffyapp.com`; download its env file.
2. Step 2: upload the env file and enter the client secret and admin password
   (secrets — do not commit them anywhere).
3. Step 3: **Run checks and activate** — this also registers the store's
   webhook URL (`https://demo.waffyapp.com/waffy/webhook`) with Waffy and
   enables the payment method.

## 6. Smoke test

Place an order on the storefront, choose **Waffy**, complete payment on the Waffy
hosted page, and confirm the Magento order moves to **Processing** with Waffy
status comments in the order history.

---

**Deployment mode:** for a demo, run production mode
(`bin/magento deploy:mode:set production`) so it's fast and realistic. Keep the
store in **Sandbox** environment (section 4) so no real money moves.

**Full module docs:** see `INSTALL.md` (Marketplace/Cloud/offline install options,
go-live steps, troubleshooting). Questions → **[support@waffyapp.com](mailto:support@waffyapp.com)**.