# cleancos — complete Docker production guide

This guide deploys cleancos on a fresh Ubuntu 26 VPS with 2 vCPUs,
4 GB RAM and a 40 GB SSD. It uses Docker Compose for the application, worker,
scheduler, MySQL and Redis, and host Nginx only as the HTTPS reverse proxy.

Follow this guide exactly and use **only this deployment method** on the VPS.
Do not install host PHP-FPM, MariaDB, Redis, Supervisor, or a second Larave
cron scheduler. Docker owns those services in this deployment.

## Use the provider-created `ubuntu` account

Use the existing `ubuntu` account for all application, Docker and deployment
commands. Do not run the application as `root`.

Confirm the account has the required access:

```sh
whoami
sudo -v
docker ps
```

If `docker ps` requires `sudo`, add the account to Docker and reconnect:

```sh
sudo usermod -aG docker "$USER"
```

Use `ubuntu` consistently for `/home/ubuntu/cleancos` and for the
GitHub Actions SSH deployment.

The Compose services are:

- `mysql`: durable MySQL database;
- `redis`: sessions, cache, locks and queues with AOF persistence;
- `app`: Laravel PHP-FPM application;
- `nginx`: internal web server serving the public build;
- `worker`: one Laravel worker consuming all application queues;
- `scheduler`: the only Laravel scheduler;
- `migrate`: a one-shot deployment task.

## 1. Before touching the VPS

You need:

1. A domain whose DNS `A` record points to the VPS.
2. The GitHub repository and permission to create a read-only deploy key.
3. An SMTP account for password-reset and operational emails.
4. Meta Pixel ID, CAPI token and production configuration entered later in
   **Admin → Suivi Meta**. Never put the Meta access token in Git or this file.
5. Navex credentials entered later in **Admin → Livraison Navex**.

Keep a record of the VPS IP, domain, SSH username, database passwords and SMTP
password in a password manager. Never commit `.env.docker`.

## 2. Prepare Ubuntu

Connect as `ubuntu` over SSH:

```sh
sudo apt update
sudo apt upgrade -y
sudo apt install -y ca-certificates curl git openssh-client unzip ufw nginx certbot python3-certbot-nginx unattended-upgrades
curl -fsSL https://get.docker.com | sudo sh
sudo apt install -y docker-compose-plugin
sudo systemctl enable --now docker nginx
sudo dpkg-reconfigure -plow unattended-upgrades
docker --version
docker compose version
```

Install the public key used by your own computer to log in to the VPS. This is
an **inbound VPS access key**. Generate this key on your computer, not on the
VPS, and paste only its `.pub` content into the file below. Never paste a
private key here.

On Windows PowerShell, create the operator key with:

```powershell
ssh-keygen -t ed25519 -f "$env:USERPROFILE\.ssh\passion-vps" -C passion-vps-operator
Get-Content "$env:USERPROFILE\.ssh\passion-vps.pub"
```

Copy the printed one-line public key, connect to the VPS as `ubuntu`, and paste
it as one complete line into `/home/ubuntu/.ssh/authorized_keys`.

```sh
sudo nano /home/ubuntu/.ssh/authorized_keys
sudo chown -R ubuntu:ubuntu /home/ubuntu/.ssh
sudo chmod 700 /home/ubuntu/.ssh
sudo chmod 600 /home/ubuntu/.ssh/authorized_keys
```

Enable the firewall:

```sh
sudo ufw allow OpenSSH
sudo ufw allow 'Nginx Full'
sudo ufw --force enable
sudo ufw status verbose
```

Reconnect as `ubuntu` so the Docker group membership is active:

```sh
ssh -i ~/.ssh/passion-vps ubuntu@YOUR_VPS_IP -p YOUR_SSH_PORT
docker ps
```

## 3. Give the VPS read-only access to GitHub

This is a different, **outbound VPS-to-GitHub key**. It is generated on the VPS
while logged in as `ubuntu`. The private key stays on the VPS; only the public
key is added in GitHub under **Repository → Settings → Deploy keys** with
**Allow read access** enabled. Do not put this public key in
`authorized_keys`.

```sh
ssh-keygen -t ed25519 -f ~/.ssh/github_cleancos -C cleancos-vps
cat ~/.ssh/github_cleancos.pub
nano ~/.ssh/config
```

Use this SSH config:

```sshconfig
Host github-cleancos
    HostName github.com
    User git
    IdentityFile ~/.ssh/github_cleancos
    IdentitiesOnly yes
```

Test and clone:

```sh
chmod 600 ~/.ssh/github_cleancos ~/.ssh/config
ssh -T git@github-cleancos
mkdir -p /home/ubuntu
git clone git@github-cleancos:YOUR_GITHUB_ACCOUNT/YOUR_REPOSITORY.git /home/ubuntu/cleancos
cd /home/ubuntu/cleancos
git checkout main
```

Run the GitHub SSH test as `ubuntu`, without `sudo`. `sudo ssh` uses root's
separate SSH configuration and will not find the `github-cleancos`
host alias stored in `/home/ubuntu/.ssh/config`.

## 4. Create the production environment file

Copy the template and protect it:

```sh
cp .env.docker.example .env.docker
chmod 600 .env.docker
nano .env.docker
```

The following is the complete production baseline. Replace every
`REPLACE_WITH_*`, the domain, email address and Sentry DSNs. Leave internal
Docker hosts exactly as shown.

```dotenv
APP_NAME="cleancos"
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:REPLACE_WITH_32_BYTE_RANDOM_KEY
APP_URL=https://boutique.example.tn
APP_LOCALE=fr
APP_FALLBACK_LOCALE=fr
APP_FAKER_LOCALE=fr_FR
APP_MAINTENANCE_DRIVER=file
# APP_MAINTENANCE_STORE=database
# PHP_CLI_SERVER_WORKERS=4
BCRYPT_ROUNDS=12

LOG_CHANNEL=stack
LOG_STACK=daily
LOG_DAILY_DAYS=21
LOG_DEPRECATIONS_CHANNEL=null
LOG_LEVEL=warning

DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=cleancos
DB_USERNAME=passion_app
DB_PASSWORD=REPLACE_WITH_LONG_DATABASE_PASSWORD
MYSQL_ROOT_PASSWORD=REPLACE_WITH_DIFFERENT_LONG_ROOT_PASSWORD

REDIS_CLIENT=predis
REDIS_HOST=redis
REDIS_PORT=6379
REDIS_PASSWORD=REPLACE_WITH_LONG_REDIS_PASSWORD
REDIS_DB=0
REDIS_CACHE_DB=1
REDIS_PREFIX=cleancos-production-

CACHE_STORE=redis
# Leave commented when Redis is dedicated to this app; Laravel derives the
# cache prefix from APP_NAME. Set a unique value only when sharing Redis.
# CACHE_PREFIX=cleancos-production-cache-
SESSION_DRIVER=redis
SESSION_STORE=redis
SESSION_ENCRYPT=true
SESSION_LIFETIME=120
SESSION_EXPIRE_ON_CLOSE=false
SESSION_COOKIE=cleancos-session
SESSION_PATH=/
SESSION_DOMAIN=null
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax
ADMIN_ABSOLUTE_SESSION_MINUTES=480

QUEUE_CONNECTION=redis
QUEUE_FAILED_DRIVER=database-uuids
REDIS_QUEUE_CONNECTION=default
REDIS_QUEUE=default
REDIS_QUEUE_RETRY_AFTER=90
BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local

ADMIN_ORDER_POLL_ENABLED=true
ADMIN_ORDER_POLL_VISIBLE_SECONDS=60
ADMIN_ORDER_POLL_HIDDEN_SECONDS=120
CHECKOUT_DRAFT_ABANDONMENT_MINUTES=15

SECURITY_CSP_MODE=report-only
SECURITY_HSTS_ENABLED=false

# Keep false until the first backup and dry run have been reviewed.
OPERATIONS_PRUNING_ENABLED=false
OPERATIONS_META_SUCCESS_RETENTION_DAYS=30
OPERATIONS_META_TERMINAL_RETENTION_DAYS=90
OPERATIONS_META_PURCHASE_ATTEMPT_RETENTION_DAYS=365
OPERATIONS_FAILED_JOBS_RETENTION_DAYS=45
OPERATIONS_TEMP_UPLOAD_RETENTION_HOURS=48
OPERATIONS_AUDIT_LOG_RETENTION_DAYS=730
OPERATIONS_SCHEDULER_MAX_AGE_MINUTES=5
OPERATIONS_QUEUE_MAX_AGE_MINUTES=5
OPERATIONS_STARTUP_GRACE_MINUTES=10
OPERATIONS_PRUNING_MAX_AGE_HOURS=30
OPERATIONS_FAILED_JOBS_WARNING_COUNT=10
OPERATIONS_DISK_WARNING_PERCENT=70
OPERATIONS_DISK_ELEVATED_PERCENT=80
OPERATIONS_DISK_CRITICAL_PERCENT=90
OPERATIONS_RELEASE_PATH=/var/www/html
OPERATIONS_BACKUP_PATH=/var/backups/cleancos

META_GRAPH_API_VERSION=v25.0
# META_TEST_EVENT_SOURCE_URL=https://boutique.example.tn

NAVEX_API_BASE_URL=https://app.navex.tn
NAVEX_ALLOWED_HOSTS=app.navex.tn
NAVEX_CONNECT_TIMEOUT_SECONDS=5
NAVEX_TIMEOUT_SECONDS=20
NAVEX_SYNC_INTERVAL_MINUTES=15
NAVEX_SYNC_BATCH_SIZE=50

MAIL_MAILER=smtp
MAIL_SCHEME=tls
MAIL_HOST=REPLACE_WITH_SMTP_HOST
MAIL_PORT=587
MAIL_USERNAME=REPLACE_WITH_SMTP_USERNAME
MAIL_PASSWORD=REPLACE_WITH_SMTP_PASSWORD
MAIL_EHLO_DOMAIN=boutique.example.tn
MAIL_FROM_ADDRESS="bonjour@boutique.example.tn"
MAIL_FROM_NAME="${APP_NAME}"

SENTRY_LARAVEL_DSN=REPLACE_WITH_SERVER_SENTRY_DSN
SENTRY_ENVIRONMENT=production
SENTRY_RELEASE=
SENTRY_SEND_DEFAULT_PII=false
SENTRY_TRACES_SAMPLE_RATE=0.1
SENTRY_PROFILES_SAMPLE_RATE=0
SENTRY_ENABLE_LOGS=false
SENTRY_ENABLE_METRICS=false

VITE_APP_NAME="${APP_NAME}"
VITE_SENTRY_DSN=REPLACE_WITH_BROWSER_SENTRY_DSN
VITE_SENTRY_ENVIRONMENT=production
VITE_SENTRY_RELEASE=
VITE_SENTRY_TRACES_SAMPLE_RATE=0.1

# The container Nginx is private; host Nginx terminates HTTPS.
HTTP_PORT=127.0.0.1:8080
APP_IMAGE_TAG=production
```

Generate a key and strong passwords without putting them in shell history:

```sh
openssl rand -base64 32
openssl rand -base64 36
openssl rand -base64 36
```

Set `APP_KEY` to `base64:` followed immediately by the first output. Use the
other outputs for the application database password, MySQL root password and
Redis password. Check the file:

```sh
grep -n 'REPLACE_WITH' .env.docker
chmod 600 .env.docker
```

Every replacement must be complete before deployment.

## 5. Configure host Nginx and HTTPS

The application container listens only on `127.0.0.1:8080`. Create the host
reverse-proxy configuration as `root`:

### 5.1 Cloudflare proxy: preserve the real visitor IP

If the DNS records are **proxied by Cloudflare** (orange cloud), configure this
before enabling the site. Without it, host Nginx sees a Cloudflare IP as the
remote address; Laravel rate limits, audit information and Meta Conversions API
would then receive a proxy/container address instead of the visitor's public
IP.

This configuration trusts `CF-Connecting-IP` **only** from Cloudflare's
published IP ranges. Do not trust a client-supplied `X-Forwarded-For` header
from arbitrary public addresses.

Create the safe updater. It downloads Cloudflare's current IPv4 and IPv6
ranges, validates the result, keeps the previous file if Nginx rejects the new
one, and reloads Nginx only after a successful validation:

```sh
sudo install -d -m 755 /etc/nginx/snippets
sudo tee /usr/local/sbin/update-cloudflare-realip >/dev/null <<'EOF'
#!/bin/sh
set -eu

target=/etc/nginx/snippets/cloudflare-realip.conf
work_dir=$(mktemp -d)
trap 'rm -rf "$work_dir"' EXIT

curl --fail --silent --show-error --max-time 20 https://www.cloudflare.com/ips-v4 > "$work_dir/ips-v4"
curl --fail --silent --show-error --max-time 20 https://www.cloudflare.com/ips-v6 > "$work_dir/ips-v6"

grep -Eq '^[0-9]{1,3}(\.[0-9]{1,3}){3}/[0-9]{1,2}$' "$work_dir/ips-v4"
grep -Eq '^[0-9A-Fa-f:]+/[0-9]{1,3}$' "$work_dir/ips-v6"

{
    printf '%s\n' 'real_ip_header CF-Connecting-IP;' 'real_ip_recursive on;'
    sed -n 's#^\([0-9][0-9.]*\)/\([0-9][0-9]*\)$#set_real_ip_from \1/\2;#p' "$work_dir/ips-v4"
    sed -n 's#^\([0-9A-Fa-f:][0-9A-Fa-f:]*\)/\([0-9][0-9]*\)$#set_real_ip_from \1/\2;#p' "$work_dir/ips-v6"
} > "$work_dir/cloudflare-realip.conf"

test "$(grep -c '^set_real_ip_from ' "$work_dir/cloudflare-realip.conf")" -ge 2

if [ -f "$target" ]; then
    cp "$target" "$work_dir/previous.conf"
    had_previous=1
else
    had_previous=0
fi

install -m 644 "$work_dir/cloudflare-realip.conf" "$target"

if ! nginx -t; then
    if [ "$had_previous" -eq 1 ]; then
        install -m 644 "$work_dir/previous.conf" "$target"
    else
        rm -f "$target"
    fi
    exit 1
fi

systemctl reload nginx
EOF
sudo chmod 755 /usr/local/sbin/update-cloudflare-realip
```

The Nginx site configuration below includes the generated file. After you have
enabled that site, run `sudo /usr/local/sbin/update-cloudflare-realip` before
the first `nginx -t`. Keep the ranges current with this root cron entry:

```sh
sudo crontab -e
```

```cron
17 4 * * * /usr/local/sbin/update-cloudflare-realip >> /var/log/cloudflare-realip-update.log 2>&1
```

If the domain is **DNS-only** (grey cloud), skip this subsection and omit the
`include` line below. If you change Cloudflare's SSL/TLS mode, use **Full
(strict)** after the origin certificate has been issued; never use Flexible.

```sh
sudo nano /etc/nginx/sites-available/cleancos
```

Use:

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name boutique.example.tn www.boutique.example.tn;
    # Laravel limits each product image to 2 MB. This allows multipart
    # editor saves containing several images without a host-level 413.
    client_max_body_size 256m;

    # Required only when the Cloudflare proxy (orange cloud) is enabled.
    include /etc/nginx/snippets/cloudflare-realip.conf;

    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_read_timeout 60s;
    }
}
```

Enable and obtain TLS:

```sh
sudo ln -s /etc/nginx/sites-available/cleancos /etc/nginx/sites-enabled/cleancos
sudo rm -f /etc/nginx/sites-enabled/default
sudo /usr/local/sbin/update-cloudflare-realip
sudo certbot --nginx -d boutique.example.tn -d www.boutique.example.tn
```

For a DNS-only deployment, replace the updater command above with:

```sh
sudo nginx -t
sudo systemctl reload nginx
```

After HTTPS works, set these values in `.env.docker` and redeploy:

```dotenv
APP_URL=https://boutique.example.tn
SECURITY_CSP_MODE=enforce
SECURITY_HSTS_ENABLED=true
```

## 6. Build and start the complete stack

Make the scripts executable and validate Compose before starting:

```sh
chmod 755 scripts/docker-deploy.sh scripts/docker-backup.sh
docker compose --env-file .env.docker config --quiet
```

Run the idempotent deployment script:

```sh
sh scripts/docker-deploy.sh
```

The script starts MySQL and Redis, waits for health checks, builds the
production PHP/Vite image, runs migrations and Laravel caches, then starts the
application, Nginx, worker and scheduler. It does not create a second worker
or scheduler.

Check every service:
sud
```sh
docker compose --env-file .env.docker ps
docker compose --env-file .env.docker logs --tail=100 app
docker compose --env-file .env.docker logs --tail=100 worker
docker compose --env-file .env.docker logs --tail=100 scheduler
curl --fail --silent --show-error https://boutique.example.tn/up
curl --fail --silent --show-error https://boutique.example.tn/api/health/ready
```

Expected services are `running` or `healthy`: `mysql`, `redis`, `app`,
`nginx`, `worker`, and `scheduler`.

## 7. Create the first administrator

Do not place the password in shell history:

```sh
read -rsp "Super Admin password: " ADMIN_PASSWORD; echo
docker compose --env-file .env.docker exec app php artisan admin:create-super \
  --name="Owner name" --email="admin@boutique.example.tn" --password="$ADMIN_PASSWORD"
unset ADMIN_PASSWORD
```

Log in immediately and change/store the password in a password manager.

## 8. Verify queues, jobs and scheduler

The worker command consumes:

```text
meta, integrations, critical, default, media, exports
```

Inspect them:

```sh
docker compose --env-file .env.docker ps worker scheduler
docker compose --env-file .env.docker logs --tail=100 worker
docker compose --env-file .env.docker logs --tail=100 scheduler
docker compose --env-file .env.docker exec app php artisan queue:failed
docker compose --env-file .env.docker exec app php artisan schedule:list
```

The worker and scheduler use `restart: unless-stopped`. A crash, Docker
restart, or VPS reboot starts them again. Do not run `queue:work` or
`schedule:work` manually on the host. A deliberately stopped container stays
stopped until `docker compose up -d` is run.

The scheduler runs Meta requeue, Navex synchronization, heartbeats, checkout
idempotency cleanup and daily operational pruning. Queue processing is
asynchronous; Meta or Navex failure never rolls back an order.

## 9. Configure Meta and Navex in the admin

In **Admin → Suivi Meta**:

1. Enter the Pixel ID and CAPI token.
2. Select Test mode only while using a Meta Test Event Code.
3. Save and run **Tester la connexion serveur**.
4. For production, select Production mode and leave Test Event Code empty.
5. Verify the production domain and configure the Facebook domain-verification
   meta tag if Meta provides one.

The token is encrypted by Laravel and is never stored in `.env.docker`.

In **Admin → Livraison Navex**, enter the Navex credentials and run the
connection test. Do not enable shipment creation until the domain, address
fields and business rules have been verified.

## 10. First backup and retention

Create the first backup before enabling destructive operational pruning:

```sh
sudo install -d -m 750 -o ubuntu -g ubuntu /var/backups/cleancos
BACKUP_DIRECTORY=/var/backups/cleancos \
  KEEP_SETS=2 \
  sh scripts/docker-backup.sh
```

The script backs up MySQL and `storage/app`, validates gzip files and SHA-256
checksums, and retains only the two newest complete backup sets. Redis is
persistent for queue recovery but is not treated as the authoritative business
backup; MySQL is the source of truth.

After reviewing the backup:

```sh
nano .env.docker
# Change OPERATIONS_PRUNING_ENABLED=false to true, then recreate the
# containers so the new environment is actually loaded.
sh scripts/docker-deploy.sh
docker compose --env-file .env.docker exec app \
  php artisan maintenance:prune-operational-data --dry-run
docker compose --env-file .env.docker exec app \
  php artisan audit:prune-retention --dry-run
```

Redeploy after changing the environment:

```sh
sh scripts/docker-deploy.sh
```

The operational pruner runs daily through the scheduler. It removes eligible
old non-Purchase Meta diagnostics, completed attempts, failed jobs and
temporary uploads. A separate audit-only pruning command runs monthly on the
first day at 03:25. It deletes only `audit_logs` strictly older than
`OPERATIONS_AUDIT_LOG_RETENTION_DAYS` (default: 730 days). Neither command
removes orders, order status history, inventory, Navex records, Purchase event
records, or pending Meta events.

Download the two local backup sets to a separate computer at least weekly:

```sh
ls -lh /var/backups/cleancos
```

From Windows PowerShell, for each matching file:

```powershell
scp ubuntu@YOUR_VPS_IP:/var/backups/cleancos/db-*.sql.gz .
scp ubuntu@YOUR_VPS_IP:/var/backups/cleancos/storage-*.tar.gz .
scp ubuntu@YOUR_VPS_IP:/var/backups/cleancos/SHA256SUMS-* .
```

Never run `docker compose down -v`; it deletes Docker volumes and therefore
the database, Redis data and application files.

## 11. Restore procedure

Never restore over production as a first test. Restore into a temporary VPS or
temporary Compose project. Verify checksums first:

```sh
sha256sum -c SHA256SUMS-TIMESTAMP
```

A MySQL restore uses the `mysql` service and the credentials in `.env.docker`:

```sh
gzip -dc db-TIMESTAMP.sql.gz | \
  docker compose --env-file .env.docker exec -T mysql sh -c \
  'MYSQL_PWD="$MYSQL_PASSWORD" mysql -u"$MYSQL_USER" "$MYSQL_DATABASE"'
```

Restore application storage only after verifying the destination volume:

```sh
gzip -dc storage-TIMESTAMP.tar.gz | \
  docker compose --env-file .env.docker run --rm --no-deps \
  --entrypoint sh app -c 'tar -xzf - -C /var/www/html/storage/app'
```

After a restore, run migrations if required, rebuild Laravel caches and verify
the health endpoints, storefront, admin login, images, queue and scheduler.

## 12. Updating the production release

Every release is:

```sh
cd /home/ubuntu/cleancos
git pull --ff-only origin main
sh scripts/docker-deploy.sh
docker compose --env-file .env.docker ps
docker compose --env-file .env.docker exec app php artisan about
```

The script recreates the app, worker, scheduler and Nginx containers using the
new image. MySQL, Redis and `app_storage` volumes remain intact. Queue jobs
already in Redis remain available; Meta outbox rows are durable in MySQL and
are requeued by the scheduler when eligible.

Before a destructive migration, run the backup script and verify its checksum.

## 13. Reboot and failure recovery

Docker is enabled at boot. After a reboot:

```sh
docker compose --env-file .env.docker ps
curl --fail --silent --show-error https://boutique.example.tn/up
docker compose --env-file .env.docker exec app php artisan queue:failed
docker compose --env-file .env.docker logs --tail=100 worker
docker compose --env-file .env.docker logs --tail=100 scheduler
```

If a service was intentionally stopped:

```sh
docker compose --env-file .env.docker up -d
```

If an image or container is unhealthy, inspect logs before restarting. Do not
delete volumes during troubleshooting.

## 14. GitHub Actions deployment

GitHub Actions needs a third, **outbound Actions-to-VPS key**. Generate this
key on your own computer or in a secure administration environment. Add only
its public key to `/home/ubuntu/.ssh/authorized_keys` on the VPS. Store the
private key as the GitHub secret `SSH_KEY`. This key is separate from both the
operator login key and the VPS-to-GitHub read-only deploy key.

Then configure repository secrets:

| Secret | Value |
|---|---|
| `SSH_HOST` | VPS hostname or IP |
| `SSH_PORT` | `22` unless changed |
| `SSH_USER` | `ubuntu` |
| `SSH_KEY` | private Actions key |
| `DEPLOY_PATH` | `/home/ubuntu/cleancos` |
| `VITE_SENTRY_DSN` | optional browser DSN |

The workflow must run `git pull --ff-only` and `sh scripts/docker-deploy.sh`.
The repository workflow is Docker-only: every push to `main` runs the quality
gate first, then connects over SSH and deploys this Compose stack. Set the
workflow's `production` environment approval rules in GitHub if you want a
manual approval before production deployment.
It must never execute `docker compose down -v`, delete volumes, or expose
`.env.docker` in logs.

## 15. Security and maintenance checklist

Weekly:

- download both backup sets to a separate computer;
- confirm checksum files;
- inspect `docker compose ps` and health endpoints;
- review failed jobs and Sentry errors;
- check `df -h` and Docker disk usage.

Monthly:

```sh
sudo apt update
sudo apt list --upgradable
sudo apt upgrade -y
docker image prune -f
docker system df
```

Do not run `docker system prune --volumes`. Test a backup restoration monthly.
Reboot only after confirming a recent backup, then repeat the health checks.

## 16. Final acceptance checklist

The deployment is ready for visitors only when all are true:

- DNS resolves to the VPS and HTTPS has a valid certificate;
- `/up` and `/api/health/ready` return successfully;
- all six Compose services are running or healthy;
- `queue:failed` is empty;
- scheduler logs show recurring jobs;
- product images load from persistent storage;
- admin login and password reset work through SMTP;
- a test order completes without requiring Meta or Navex;
- Meta Test mode has accepted a test event;
- Meta Production mode is enabled only after the test is complete;
- Navex is configured and tested separately;
- the first backup exists and its checksum passes;
- the two-backup rotation and weekly download process are understood;
- no host Supervisor, host PHP-FPM, host MariaDB, host Redis, or second cron
  scheduler is installed for this Docker deployment.
