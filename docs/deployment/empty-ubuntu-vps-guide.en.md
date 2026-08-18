# Complete guide: deploy ToutDispo on an empty Ubuntu VPS

This English guide mirrors the French deployment guide. It is written for a
person with no Laravel experience.

It assumes:

- a new Ubuntu 24.04 LTS or Ubuntu 26.04 LTS VPS;
- a domain already pointed at the VPS IP address;
- `root` access to the VPS;
- access to the ToutDispo GitHub repository;
- GitHub Actions enabled in the repository.

Never paste passwords, SSH keys, Meta tokens, or the `.env` file into GitHub,
chat, tickets, or email.

Examples in this guide use:

```text
DOMAIN=boutique.example.tn
APP_PATH=/var/www/ToutDispo/current
DEPLOY_USER=deploy
```

Replace these values everywhere before running a command. Sections 1–4 and
8–12 must be run as `root` or with `sudo`. Git and Laravel commands run as
`deploy` unless the guide says otherwise.

## 1. Prepare the VPS

Log in as `root`. Update Ubuntu and install Nginx, PHP-FPM, MariaDB, Redis,
Supervisor, cron, Composer, and Certbot. Unversioned PHP packages install the
maintained PHP version for the installed Ubuntu release.

```sh
apt update && apt upgrade -y
apt install -y nginx mariadb-server redis-server supervisor cron git unzip curl ca-certificates \
  php-cli php-fpm php-mysql php-redis php-mbstring php-xml php-curl php-zip php-gd php-intl \
  certbot python3-certbot-nginx

php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
php composer-setup.php --install-dir=/usr/local/bin --filename=composer
rm composer-setup.php
composer --version
php -v

PHP_FPM_SERVICE="$(systemctl list-unit-files 'php*-fpm.service' --no-legend | awk '$1 ~ /^php[0-9.]+-fpm.service$/ { print $1; exit }')"
test -n "$PHP_FPM_SERVICE"
PHP_FPM_SOCKET="/run/php/${PHP_FPM_SERVICE%.service}.sock"
test -S "$PHP_FPM_SOCKET"
printf 'PHP-FPM service: %s\nPHP-FPM socket: %s\n' "$PHP_FPM_SERVICE" "$PHP_FPM_SOCKET"
```

Keep the displayed PHP-FPM service and socket. You will use them later.

Node.js is not needed on the VPS when GitHub Actions builds Vite assets. Install
Node 24 only for an emergency VPS-side frontend build.

Enable services so they start after every reboot:

```sh
systemctl enable --now nginx mariadb redis-server supervisor cron "$PHP_FPM_SERVICE"
systemctl status nginx mariadb redis-server supervisor cron "$PHP_FPM_SERVICE"
```

Configure the firewall. Keep your current SSH session open until you verify a
second SSH connection works.

```sh
ufw allow OpenSSH
ufw allow 'Nginx Full'
ufw enable
ufw status
```

## 2. Create the deployment user and secure SSH

```sh
adduser deploy
usermod -aG www-data deploy
install -d -m 700 -o deploy -g deploy /home/deploy/.ssh
```

Add the administrator's public SSH key to:

```text
/home/deploy/.ssh/authorized_keys
```

Verify a new terminal can connect:

```sh
ssh deploy@YOUR_VPS_IP
```

Only after that succeeds, disable root and password authentication in
`/etc/ssh/sshd_config`, then run:

```sh
systemctl reload ssh
```

## 3. Configure MariaDB

Run:

```sh
mysql_secure_installation
mysql -u root -p
```

Inside MariaDB, replace `LONG_DATABASE_PASSWORD` with a unique password stored
only in a password manager and the server `.env` file.

```sql
CREATE DATABASE ToutDispo CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'passion_app'@'127.0.0.1' IDENTIFIED BY 'LONG_DATABASE_PASSWORD';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP, REFERENCES
  ON ToutDispo.* TO 'passion_app'@'127.0.0.1';
FLUSH PRIVILEGES;
EXIT;
```

Never expose MariaDB to the Internet. It must remain bound to `127.0.0.1` or a
private network only.

## 4. Configure Redis

Redis holds Laravel sessions, cache, locks, rate limits, and queues. It must
not be public. Check `/etc/redis/redis.conf`:

```text
bind 127.0.0.1 ::1
protected-mode yes
```

If your policy requires a Redis password, configure it before continuing. Then:

```sh
systemctl restart redis-server
redis-cli ping
```

The expected response is `PONG`.

## 5. Give the VPS read-only GitHub access

As the `deploy` user:

```sh
sudo -iu deploy
ssh-keygen -t ed25519 -f ~/.ssh/github_ToutDispo -C "passion-vps-readonly"
cat ~/.ssh/github_ToutDispo.pub
```

In GitHub, open **Repository → Settings → Deploy keys → Add deploy key**. Paste
the public key and leave write access disabled.

Create `/home/deploy/.ssh/config`:

```text
Host github.com
  HostName github.com
  User git
  IdentityFile ~/.ssh/github_ToutDispo
  IdentitiesOnly yes
```

Then verify access:

```sh
chmod 600 ~/.ssh/config
ssh -T git@github.com
```

## 6. Clone the application and set permissions

As `root`:

```sh
mkdir -p /var/www/ToutDispo
chown -R deploy:www-data /var/www/ToutDispo
```

As `deploy`:

```sh
git clone git@github.com:YOUR_ACCOUNT/YOUR_REPOSITORY.git /var/www/ToutDispo/current
cd /var/www/ToutDispo/current
git checkout main
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
cp .env.example .env
chmod 640 .env
chown deploy:www-data .env
mkdir -p storage/app/public storage/app/private storage/logs bootstrap/cache
chown -R deploy:www-data storage bootstrap/cache
find storage bootstrap/cache -type d -exec chmod 775 {} \;
find storage bootstrap/cache -type f -exec chmod 664 {} \;
```

`storage` and `bootstrap/cache` must be writable by `deploy` and `www-data`.
Nginx must serve only the `public` directory.

## 7. Create the production `.env`

Copy this into `/var/www/ToutDispo/current/.env`. Replace only values in
angle brackets. Do not commit this file.

```dotenv
APP_NAME="ToutDispo"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://boutique.example.tn
APP_KEY=
APP_LOCALE=fr
APP_FALLBACK_LOCALE=fr
APP_FAKER_LOCALE=fr_FR
APP_MAINTENANCE_DRIVER=file

BCRYPT_ROUNDS=12

LOG_CHANNEL=stack
LOG_STACK=daily
LOG_DAILY_DAYS=21
LOG_DEPRECATIONS_CHANNEL=null
LOG_LEVEL=warning

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=ToutDispo
DB_USERNAME=passion_app
DB_PASSWORD=<LONG_DATABASE_PASSWORD>
DB_CHARSET=utf8mb4
DB_COLLATION=utf8mb4_unicode_ci

REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=null
REDIS_DB=0
REDIS_CACHE_DB=1
REDIS_PREFIX=ToutDispo-production-
REDIS_QUEUE_CONNECTION=default
REDIS_QUEUE=default
REDIS_QUEUE_RETRY_AFTER=90

CACHE_STORE=redis
SESSION_DRIVER=redis
SESSION_STORE=redis
SESSION_ENCRYPT=true
SESSION_LIFETIME=120
SESSION_EXPIRE_ON_CLOSE=false
SESSION_COOKIE=ToutDispo-session
SESSION_PATH=/
SESSION_DOMAIN=null
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax
ADMIN_ABSOLUTE_SESSION_MINUTES=480
ADMIN_ORDER_POLL_ENABLED=true
ADMIN_ORDER_POLL_VISIBLE_SECONDS=60
ADMIN_ORDER_POLL_HIDDEN_SECONDS=120
CHECKOUT_DRAFT_ABANDONMENT_MINUTES=15

BROADCAST_CONNECTION=log
QUEUE_CONNECTION=redis
QUEUE_FAILED_DRIVER=database-uuids
FILESYSTEM_DISK=local

SECURITY_CSP_MODE=report-only
SECURITY_HSTS_ENABLED=false

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
OPERATIONS_RELEASE_PATH=/var/www/ToutDispo
OPERATIONS_BACKUP_PATH=/var/backups/ToutDispo

SENTRY_LARAVEL_DSN=
SENTRY_ENVIRONMENT=production
SENTRY_RELEASE=
SENTRY_SEND_DEFAULT_PII=false
SENTRY_TRACES_SAMPLE_RATE=0.1
SENTRY_PROFILES_SAMPLE_RATE=0.0
SENTRY_ENABLE_LOGS=false
SENTRY_ENABLE_METRICS=false

VITE_APP_NAME="${APP_NAME}"
VITE_SENTRY_DSN=
VITE_SENTRY_ENVIRONMENT=production
VITE_SENTRY_RELEASE=
VITE_SENTRY_TRACES_SAMPLE_RATE=0.1

MAIL_MAILER=smtp
MAIL_SCHEME=tls
MAIL_HOST=<SMTP_HOST>
MAIL_PORT=587
MAIL_USERNAME=<SMTP_USERNAME>
MAIL_PASSWORD=<SMTP_PASSWORD>
MAIL_EHLO_DOMAIN=boutique.example.tn
MAIL_FROM_ADDRESS="bonjour@boutique.example.tn"
MAIL_FROM_NAME="${APP_NAME}"

META_GRAPH_API_VERSION=v25.0

The three `ADMIN_ORDER_POLL_*` settings are resolved by Laravel at runtime
and exposed only to authenticated admin pages. After changing them, run
`php artisan config:cache` and reload PHP-FPM; a new frontend build is not
required. Set `ADMIN_ORDER_POLL_ENABLED=false` to disable automatic polling;
manual refresh remains available. Values are clamped to safe bounds by the
application (visible 30–600 seconds, hidden 60–1800 seconds, hidden never
shorter than visible).
`CHECKOUT_DRAFT_ABANDONMENT_MINUTES` controls when first-party checkout drafts
become visible to administrators as abandoned checkouts; the safe default is
15 minutes.
# META_TEST_EVENT_SOURCE_URL=https://boutique.example.tn

NAVEX_API_BASE_URL=https://app.navex.tn
NAVEX_ALLOWED_HOSTS=app.navex.tn
NAVEX_CONNECT_TIMEOUT_SECONDS=5
NAVEX_TIMEOUT_SECONDS=20
NAVEX_SYNC_INTERVAL_MINUTES=15
NAVEX_SYNC_BATCH_SIZE=50
```

Generate `APP_KEY` exactly once:

```sh
cd /var/www/ToutDispo/current
php artisan key:generate --force
```

Never regenerate `APP_KEY` after Meta configuration or first launch; encrypted
data would become unreadable.

Notes:

- Add Sentry server/browser DSNs only if Sentry is enabled.
- Configure Meta Pixel ID, CAPI token, Test Event Code, and domain verification
  tag in **Meta Tracking** in the admin. Do not put those secrets in `.env`.
- SMTP is required for password reset messages. `MAIL_MAILER=log` is only a
  temporary diagnostic fallback and sends no real email.
- `VITE_*` values are baked into frontend assets at GitHub Actions build time.
  Add `VITE_SENTRY_DSN` as a GitHub Actions secret for normal CI deployment.

After TLS and CSP hosts are verified, change to:

```dotenv
SECURITY_CSP_MODE=enforce
SECURITY_HSTS_ENABLED=true
```

Then run `php artisan config:cache`.

## 8. Configure Nginx and HTTPS

Create `/etc/nginx/sites-available/ToutDispo`:

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name boutique.example.tn www.boutique.example.tn;

    root /var/www/ToutDispo/current/public;
    index index.php;
    # Product images are limited to 2 MB each by Laravel. Keep the request
    # envelope large enough for several images in one editor submission.
    client_max_body_size 256m;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        try_files $uri =404;
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:__PHP_FPM_SOCKET__;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        # Preserve the TCP peer address for Laravel's request()->ip(). Do not
        # trust client-supplied X-Forwarded-For on a direct Nginx/PHP-FPM VPS.
        fastcgi_param REMOTE_ADDR $remote_addr;
        include fastcgi_params;
    }

    location ~ /\. {
        deny all;
    }

    location ~* \.(?:css|js|mjs|woff2|webp|avif|png|jpe?g|svg)$ {
        try_files $uri =404;
        expires 1y;
        add_header Cache-Control "public, immutable";
    }
}
```

If a CDN or external load balancer is added later, configure Laravel to trust
only that provider's published proxy IP ranges before forwarding
`X-Forwarded-For`. Never trust all forwarded headers (`*`) on an
internet-facing server. Before enabling Meta Production mode, confirm one real
storefront request records the customer public IP, not `127.0.0.1`, a private
address, or the proxy address.

Replace `__PHP_FPM_SOCKET__` with the value printed in section 1. Then:

```sh
ln -s /etc/nginx/sites-available/ToutDispo /etc/nginx/sites-enabled/ToutDispo
rm -f /etc/nginx/sites-enabled/default
nginx -t
systemctl reload nginx
certbot --nginx -d boutique.example.tn -d www.boutique.example.tn
systemctl status certbot.timer
```

If needed, create `/etc/php/<PHP_VERSION>/fpm/conf.d/99-passion.ini`:

```ini
upload_max_filesize=20M
post_max_size=256M
max_file_uploads=150
memory_limit=256M
max_execution_time=60
```

Then run:

```sh
systemctl restart "$PHP_FPM_SERVICE"
```

## 9. First Laravel launch

As `deploy`:

```sh
cd /var/www/ToutDispo/current
php artisan storage:link
php artisan migrate --force
php artisan optimize:clear
php artisan optimize
php artisan about
php artisan schedule:list
curl --fail --silent --show-error https://boutique.example.tn/up
curl --fail --silent --show-error https://boutique.example.tn/api/health/live
```

Do not run demo seeders on a real shop. Create the first Super Admin without
putting its password in shell history:

```sh
read -s ADMIN_PASSWORD
php artisan admin:create-super --name="Owner name" --email="admin@boutique.example.tn" --password="$ADMIN_PASSWORD"
unset ADMIN_PASSWORD
```

Store this password in a password manager and log in immediately.

## 10. Required Laravel queue worker

The Scheduler does not process jobs. Supervisor owns the only persistent
Laravel worker. As `root`:

```sh
cp /var/www/ToutDispo/current/deploy/ubuntu/ToutDispo-worker.conf \
  /etc/supervisor/conf.d/ToutDispo-worker.conf
supervisorctl reread
supervisorctl update
supervisorctl status ToutDispo-worker:*
```

Expected status: `RUNNING`.

The worker consumes exactly: `meta`, `integrations`, `critical`, `default`,
`media`, and `exports`. After a deployment, `php artisan queue:restart` lets
the worker finish its current job and Supervisor restarts it with new code.
Never manually run another `queue:work` process.

Live worker log:

```sh
tail -f /var/log/supervisor/ToutDispo-worker.log
```

## 11. Required Laravel Scheduler

Use Ubuntu's standard cron service as the only Scheduler trigger. As `root`:

```sh
crontab -u deploy -e
```

Add exactly this one line:

```cron
* * * * * cd /var/www/ToutDispo/current && /usr/bin/php artisan schedule:run --no-interaction >> /var/log/passion/scheduler.log 2>&1
```

Create its log and inspect the crontab:

```sh
install -d -o deploy -g www-data -m 0750 /var/log/passion
touch /var/log/passion/scheduler.log
chown deploy:www-data /var/log/passion/scheduler.log
crontab -u deploy -l
```

Do not create a `schedule:work` systemd service. Do not create a second cron
entry.

Run this verification as `root`:

```sh
cd /var/www/ToutDispo/current
DEPLOY_USER=deploy sh scripts/verify-ubuntu-operations.sh /var/www/ToutDispo/current
```

The script changes nothing. It checks cron, the unique Scheduler trigger,
Supervisor worker state, log rotation, storage permissions, disk state, Laravel
operational health, and safe pruning dry-run.

## 12. Logs, rotation, and backups

As `root`:

```sh
cp /var/www/ToutDispo/current/deploy/ubuntu/ToutDispo-logrotate.conf \
  /etc/logrotate.d/ToutDispo
logrotate --debug /etc/logrotate.d/ToutDispo
install -d -o deploy -g www-data -m 0750 /var/backups/ToutDispo
```

Laravel manages `storage/logs/laravel-*.log` itself for 21 days. Logrotate must
not rotate those files.

## 13. Connect GitHub Actions to the VPS

Create a second SSH key dedicated to GitHub Actions. Add its public key to
`/home/deploy/.ssh/authorized_keys`. Add these GitHub Actions secrets:

| Secret | Value |
|---|---|
| `SSH_HOST` | VPS IP address or hostname |
| `SSH_PORT` | `22` unless changed |
| `SSH_USER` | `deploy` |
| `SSH_KEY` | GitHub Actions private key for the VPS |
| `DEPLOY_PATH` | `/var/www/ToutDispo/current` |
| `VITE_SENTRY_DSN` | optional browser Sentry DSN |

Each push to `main` now:

1. builds Vite on GitHub Actions;
2. pulls code with `git pull --ff-only`;
3. runs optimized production Composer install;
4. ensures the storage link exists;
5. uploads `public/build`;
6. runs migrations and Laravel production optimization;
7. asks Supervisor-owned workers to reload via `queue:restart`;
8. never starts a second queue worker or Scheduler.

## 14. Final checks before opening the site

As `deploy` or `root` where needed:

```sh
cd /var/www/ToutDispo/current
php artisan migrate:status
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
php artisan queue:restart
php artisan schedule:run
php artisan maintenance:prune-operational-data --dry-run
php artisan audit:prune-retention --dry-run
supervisorctl status
redis-cli ping
curl --fail --silent --show-error https://boutique.example.tn/up
curl --fail --silent --show-error https://boutique.example.tn/api/health/ready
```

Then manually test:

1. home, catalogue, and product page on desktop and mobile;
2. add to cart and quantity change;
3. one test checkout and order creation;
4. Super Admin login;
5. product image upload;
6. category and product creation;
7. Supervisor worker stays `RUNNING`;
8. Scheduler log changes every minute;
9. no failed jobs;
10. Meta remains in Test mode until owner validation is complete.

Also read [post-deploy smoke test](../runbooks/post-deploy-smoke-test.md),
[Meta production validation](../runbooks/meta-production-validation.md), and
[Ubuntu operational retention](ubuntu-operational-retention.md).

## Quick troubleshooting

| Symptom | Immediate check |
|---|---|
| Site returns 502 | `systemctl status <PHP_FPM_SERVICE> nginx`, then `tail -n 100 /var/log/nginx/error.log` |
| Site returns 500 | `tail -n 100 storage/logs/laravel-*.log`; never expose `APP_DEBUG=true` publicly |
| Images return 404 | `php artisan storage:link`, then inspect `storage/app/public` permissions |
| Meta stays pending | `supervisorctl status`, then `php artisan queue:restart`; verify worker includes `meta` |
| Scheduler is stale | `crontab -u deploy -l`, `systemctl status cron`, then `php artisan schedule:run` |
| Sessions log out | `redis-cli ping`; verify Redis session driver and secure HTTPS cookies |
| GitHub deploy fails | verify Actions secrets and that `deploy` can run `git pull --ff-only origin main` |

## 15. Automated backups and restoration test

A useful backup includes:

- the MariaDB database;
- `storage/app` public images and private files;
- a protected/encrypted `.env` copy;
- an encrypted off-VPS copy.

### 15.1 Create a backup MariaDB account

As `root`, create an account with a password stored only in a password manager:

```sh
mysql -u root -p
```

```sql
CREATE USER 'passion_backup'@'127.0.0.1' IDENTIFIED BY 'LONG_BACKUP_PASSWORD';
GRANT SELECT, SHOW VIEW, TRIGGER, EVENT, LOCK TABLES ON ToutDispo.* TO 'passion_backup'@'127.0.0.1';
FLUSH PRIVILEGES;
EXIT;
```

Create `/home/deploy/.my.cnf`:

```ini
[client]
host=127.0.0.1
user=passion_backup
password=LONG_BACKUP_PASSWORD
```

```sh
chown deploy:deploy /home/deploy/.my.cnf
chmod 600 /home/deploy/.my.cnf
```

### 15.2 Install the daily backup script

As `root`, create `/usr/local/sbin/ToutDispo-backup` with this content:

```sh
#!/usr/bin/env bash
set -euo pipefail
umask 077

APP_PATH=/var/www/ToutDispo/current
BACKUP_ROOT=/var/backups/ToutDispo/daily
STAMP=$(date -u +%Y-%m-%dT%H%M%SZ)
TARGET="$BACKUP_ROOT/$STAMP"
mkdir -p "$BACKUP_ROOT"
TEMP=$(mktemp -d "$BACKUP_ROOT/.partial.XXXXXX")
trap 'rm -rf "$TEMP"' EXIT

mysqldump --defaults-extra-file=/home/deploy/.my.cnf --single-transaction --quick \
  --routines --events --triggers --no-tablespaces ToutDispo | gzip -9 > "$TEMP/database.sql.gz"
tar -C "$APP_PATH" \
  --exclude='storage/logs' \
  --exclude='storage/framework/cache' \
  --exclude='storage/framework/sessions' \
  --exclude='storage/framework/views' \
  -czf "$TEMP/application-data.tar.gz" .env storage/app
sha256sum "$TEMP/database.sql.gz" "$TEMP/application-data.tar.gz" > "$TEMP/SHA256SUMS"
mv "$TEMP" "$TARGET"
trap - EXIT
```

Then:

```sh
chmod 750 /usr/local/sbin/ToutDispo-backup
chown root:deploy /usr/local/sbin/ToutDispo-backup
mkdir -p /var/backups/ToutDispo/daily
chown -R deploy:www-data /var/backups/ToutDispo
chmod 750 /var/backups/ToutDispo
sudo -u deploy /usr/local/sbin/ToutDispo-backup
find /var/backups/ToutDispo/daily -maxdepth 2 -type f -print
```

Add this additional `deploy` crontab line at 00:30 daily. Keep the Scheduler
line from section 11; both lines must exist.

```cron
30 0 * * * /usr/local/sbin/ToutDispo-backup >> /var/log/passion/backup.log 2>&1
```

Copy backups to encrypted offsite storage. Retain at least 7 daily, 4 weekly,
and 6 monthly copies.

### 15.3 Test restoration monthly

Never restore over the production database for a test.

1. Create a temporary database, such as `passion_restore_test`.
2. Copy the latest backup to a root-only temporary directory.
3. Run `sha256sum -c SHA256SUMS`.
4. Restore only into the temporary database:

   ```sh
   gunzip -c database.sql.gz | mysql -u root -p passion_restore_test
   ```

5. Extract `application-data.tar.gz` into a temporary directory, never the
   production directory.
6. Verify expected tables, images, and private files.
7. Record the date, duration, and outcome.
8. Delete the temporary database and temporary directory afterwards.

## 16. Security updates and routine maintenance

As `root`, enable automatic security updates:

```sh
apt install -y unattended-upgrades update-notifier-common
dpkg-reconfigure -plow unattended-upgrades
systemctl status unattended-upgrades
```

During a monthly maintenance window:

```sh
apt update
apt list --upgradable
apt upgrade -y
test -f /var/run/reboot-required && echo "Reboot required" || true
systemctl status nginx mariadb redis-server supervisor cron <PHP_FPM_SERVICE>
supervisorctl status
```

If a reboot is required, confirm a recent backup exists, reboot during a quiet
period, then repeat section 14.

Also run monthly:

```sh
cd /var/www/ToutDispo/current
composer audit
php artisan maintenance:prune-operational-data --dry-run
php artisan audit:prune-retention --dry-run
df -h
du -sh storage/logs storage/app /var/backups/ToutDispo
```

Application dependency updates must go through a branch, CI, tests, and the
normal GitHub Actions deployment—not direct production edits.

## What this guide does not do automatically

- It does not create DNS records or Meta/Sentry accounts.
- It does not fill the catalogue, content, legal pages, or business accounts.
- It does not create or test offsite backup storage for you.
- It does not activate Meta Production mode without owner approval.

## 17. Set up GitHub Actions VPS deployment secrets

There are two different SSH key directions. Do not reuse one key for both:

1. `github_ToutDispo` from section 5 lets the **VPS read the GitHub
   repository**.
2. The new `github_actions_passion` key below lets **GitHub Actions deploy to
   the VPS**.

As `deploy` on the VPS, create the GitHub Actions deployment key:

```sh
sudo -iu deploy
ssh-keygen -t ed25519 -f ~/.ssh/github_actions_passion -C "passion-github-actions-deploy"
grep -qxF "$(cat ~/.ssh/github_actions_passion.pub)" ~/.ssh/authorized_keys || cat ~/.ssh/github_actions_passion.pub >> ~/.ssh/authorized_keys
chmod 600 ~/.ssh/authorized_keys ~/.ssh/github_actions_passion
chmod 644 ~/.ssh/github_actions_passion.pub
```

Open the private key locally on the VPS and copy its complete contents only into
the GitHub secret in the next step. Never commit or send it in chat:

```sh
cat ~/.ssh/github_actions_passion
```

In GitHub, open **Repository → Settings → Secrets and variables → Actions**.
Create these **repository secrets**:

| Secret | Exact value |
|---|---|
| `SSH_HOST` | VPS public IP address or hostname |
| `SSH_PORT` | `22`, unless you changed SSH port |
| `SSH_USER` | `deploy` |
| `SSH_KEY` | complete private key from `~/.ssh/github_actions_passion` |
| `DEPLOY_PATH` | `/var/www/ToutDispo/current` |
| `VITE_SENTRY_DSN` | optional browser Sentry DSN; leave absent if unused |

In **Repository → Settings → Secrets and variables → Actions → Variables**,
either leave `DEPLOY_MODE` absent or set it to `vps`. Do not set it to `docker`
for this VPS installation.

The CI workflow builds Vite on GitHub Actions, uploads `public/build` to the
VPS, then runs `scripts/production-optimize.sh`. The VPS does not need Node or
`npm install` for normal deployments.

Before the first deployment, verify the VPS can pull the repository as
`deploy`:

```sh
sudo -iu deploy
cd /var/www/ToutDispo/current
git pull --ff-only origin main
```

To test deployment without a code change, open **GitHub → Actions → CI → Run
workflow**, select `main`, and click **Run workflow**. Review the three
non-Docker deployment steps: prepare code, upload Vite assets, and finalize
Laravel optimization. Then run the section 14 smoke checks on the VPS.
