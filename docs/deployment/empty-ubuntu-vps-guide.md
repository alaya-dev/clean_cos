# Guide complet : installer ToutDispo sur un VPS Ubuntu vide

Ce guide est destiné à une personne qui ne connaît pas Laravel. Il suppose :

- un VPS Ubuntu 24.04 LTS ou Ubuntu 26.04 LTS vierge ;
- un nom de domaine déjà dirigé vers l'adresse IP du VPS ;
- l'accès administrateur (`root`) au VPS ;
- l'accès au dépôt GitHub de ToutDispo ;
- GitHub Actions activé dans le dépôt.

Ne copiez jamais un mot de passe, une clé SSH, un jeton Meta ou le fichier
`.env` dans GitHub, dans un ticket ou dans une discussion.

Les exemples utilisent :

```text
DOMAINE=boutique.example.tn
CHEMIN=/var/www/ToutDispo/current
UTILISATEUR=deploy
```

Remplacez ces valeurs partout avant d'exécuter les commandes.

Les commandes des sections 1 à 4 et 8 à 12 doivent être exécutées en `root`
ou précédées de `sudo`. Les commandes Git/Laravel sont exécutées avec
l'utilisateur `deploy`.

## 1. Préparer le VPS

Connectez-vous une première fois en `root`, mettez le système à jour puis
installez les composants requis : Nginx, PHP-FPM, MariaDB, Redis, Supervisor,
cron, Composer et Certbot. Les paquets PHP sans numéro installent la version
maintenue par votre version d'Ubuntu ; cela évite de supposer PHP 8.3 sur un
VPS Ubuntu 26.

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
printf 'Service PHP-FPM : %s\nSocket PHP-FPM : %s\n' "$PHP_FPM_SERVICE" "$PHP_FPM_SOCKET"
```

L'installation initiale ne remplace pas les mises à jour récurrentes : la
routine de sécurité obligatoire est décrite à la [section 16](#16-mises-à-jour-de-sécurité-et-maintenance-régulière).

Node.js n'est pas requis sur le VPS lorsque GitHub Actions construit les assets
Vite. Installez Node 24 seulement si vous devez faire un build d'urgence sur le
VPS.

Activez les services :

```sh
systemctl enable --now nginx mariadb redis-server supervisor cron "$PHP_FPM_SERVICE"
systemctl status nginx mariadb redis-server supervisor cron "$PHP_FPM_SERVICE"
```

Configurez le pare-feu. Gardez SSH ouvert avant de fermer votre session :

```sh
ufw allow OpenSSH
ufw allow 'Nginx Full'
ufw enable
ufw status
```

## 2. Créer l'utilisateur de déploiement et sécuriser SSH

```sh
adduser deploy
usermod -aG www-data deploy
install -d -m 700 -o deploy -g deploy /home/deploy/.ssh
```

Ajoutez la clé publique SSH de la personne qui administrera le serveur dans :

```text
/home/deploy/.ssh/authorized_keys
```

Puis vérifiez dans un nouveau terminal que cette personne peut se connecter :

```sh
ssh deploy@IP_DU_VPS
```

Seulement après cette vérification, désactivez la connexion root et par mot de
passe dans `/etc/ssh/sshd_config`, puis exécutez :

```sh
systemctl reload ssh
```

## 3. Configurer MariaDB

Exécutez d'abord :

```sh
mysql_secure_installation
```

Créez une base et un compte dédiés. Remplacez `MOT_DE_PASSE_DATABASE_LONG` par
un mot de passe aléatoire stocké uniquement dans un gestionnaire de mots de
passe et dans `.env`.

```sh
mysql -u root -p
```

Dans MariaDB :

```sql
CREATE DATABASE ToutDispo CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'passion_app'@'127.0.0.1' IDENTIFIED BY 'MOT_DE_PASSE_DATABASE_LONG';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP, REFERENCES
  ON ToutDispo.* TO 'passion_app'@'127.0.0.1';
FLUSH PRIVILEGES;
EXIT;
```

Ne rendez jamais MariaDB accessible depuis Internet. Elle doit rester liée à
`127.0.0.1` ou au réseau privé du VPS.

## 4. Configurer Redis

Redis porte les sessions, cache, verrous, limites de débit et files Laravel.
Il ne doit pas être exposé publiquement.

Vérifiez `/etc/redis/redis.conf` :

```text
bind 127.0.0.1 ::1
protected-mode yes
```

Ajoutez un mot de passe Redis si votre politique l'exige, redémarrez, puis
testez :

```sh
systemctl restart redis-server
redis-cli ping
```

La réponse attendue est `PONG`.

## 5. Donner au VPS un accès GitHub en lecture seule

Connecté en tant que `deploy` :

```sh
sudo -iu deploy
ssh-keygen -t ed25519 -f ~/.ssh/github_ToutDispo -C "passion-vps-readonly"
cat ~/.ssh/github_ToutDispo.pub
```

Dans GitHub : dépôt → **Settings** → **Deploy keys** → **Add deploy key**.
Collez cette clé publique et laissez l'écriture désactivée.

Créez ensuite `/home/deploy/.ssh/config` :

```text
Host github.com
  HostName github.com
  User git
  IdentityFile ~/.ssh/github_ToutDispo
  IdentitiesOnly yes
```

Puis :

```sh
chmod 600 ~/.ssh/config
ssh -T git@github.com
```

## 6. Cloner l'application et configurer les permissions

En `root`, préparez le répertoire :

```sh
mkdir -p /var/www/ToutDispo
chown -R deploy:www-data /var/www/ToutDispo
```

Puis connectez-vous avec l'utilisateur `deploy` :

```sh
git clone git@github.com:VOTRE_COMPTE/VOTRE_DEPOT.git /var/www/ToutDispo/current
cd /var/www/ToutDispo/current
git checkout main
composer install --no-dev --optimize-autoloader --no-interaction
cp .env.example .env
chmod 640 .env
chown deploy:www-data .env
mkdir -p storage/app/public storage/app/private storage/logs bootstrap/cache
chown -R deploy:www-data storage bootstrap/cache
find storage bootstrap/cache -type d -exec chmod 775 {} \;
find storage bootstrap/cache -type f -exec chmod 664 {} \;
```

`storage` et `bootstrap/cache` doivent être inscriptibles par `deploy` et
`www-data`. Le répertoire `public` est le seul répertoire servi par Nginx.

## 7. Créer le fichier `.env` de production

Copiez ce bloc dans `/var/www/ToutDispo/current/.env`, puis remplacez
uniquement les valeurs entre chevrons. Il correspond aux variables lues par
l'application en production. Ne mettez jamais ce fichier dans Git.

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
DB_PASSWORD=<MOT_DE_PASSE_DATABASE_LONG>
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
MAIL_HOST=<HOTE_SMTP>
MAIL_PORT=587
MAIL_USERNAME=<UTILISATEUR_SMTP>
MAIL_PASSWORD=<MOT_DE_PASSE_SMTP>
MAIL_EHLO_DOMAIN=boutique.example.tn
MAIL_FROM_ADDRESS="bonjour@boutique.example.tn"
MAIL_FROM_NAME="${APP_NAME}"

META_GRAPH_API_VERSION=v25.0
# META_TEST_EVENT_SOURCE_URL=https://boutique.example.tn

NAVEX_API_BASE_URL=https://app.navex.tn
NAVEX_ALLOWED_HOSTS=app.navex.tn
NAVEX_CONNECT_TIMEOUT_SECONDS=5
NAVEX_TIMEOUT_SECONDS=20
NAVEX_SYNC_INTERVAL_MINUTES=15
NAVEX_SYNC_BATCH_SIZE=50
```

Générez `APP_KEY` une seule fois :

```sh
cd /var/www/ToutDispo/current
php artisan key:generate --force
```

Ne régénérez jamais `APP_KEY` après avoir enregistré une configuration Meta ou
après le premier lancement : cela rendrait les données chiffrées existantes
illisibles.

### Variables optionnelles

- Sentry : renseignez les DSN serveur et navigateur si Sentry est activé.
- Meta : le Pixel ID, le jeton CAPI, le code Test et la balise de vérification
  de domaine sont enregistrés chiffrés ou gérés dans **Suivi Meta**. Ne les
  ajoutez pas à `.env`.
- Mail : le SMTP est nécessaire pour la réinitialisation de mot de passe. Si le
  prestataire mail n'est pas encore prêt, utilisez temporairement
  `MAIL_MAILER=log`, mais aucun e-mail ne sera livré.
- AWS/S3 : laissez les variables AWS vides tant que le disque local est utilisé.

Après le premier certificat TLS validé et les hôtes CSP vérifiés, passez à :

```dotenv
SECURITY_CSP_MODE=enforce
SECURITY_HSTS_ENABLED=true
```

Puis exécutez `php artisan config:cache`.

## 8. Configurer Nginx et HTTPS

Créez `/etc/nginx/sites-available/ToutDispo` :

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

Remplacez le marqueur `__PHP_FPM_SOCKET__` par le socket affiché à la section 1,
puis activez le site et vérifiez la configuration :

```sh
ln -s /etc/nginx/sites-available/ToutDispo /etc/nginx/sites-enabled/ToutDispo
rm -f /etc/nginx/sites-enabled/default
nginx -t
systemctl reload nginx
```

Une fois le DNS propagé, obtenez le certificat :

```sh
certbot --nginx -d boutique.example.tn -d www.boutique.example.tn
systemctl status certbot.timer
```

Ajoutez si nécessaire `/etc/php/<VERSION_PHP>/fpm/conf.d/99-passion.ini`, où
`<VERSION_PHP>` est la version affichée dans le nom du service PHP-FPM de la
section 1, par exemple `8.3` ou `8.5` :

```ini
upload_max_filesize=20M
post_max_size=256M
max_file_uploads=150
memory_limit=256M
max_execution_time=60
```

Puis :

```sh
systemctl restart "$PHP_FPM_SERVICE"
```

## 9. Premier lancement Laravel

En tant que `deploy` :

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

Ne lancez pas de seeder de démonstration sur une boutique réelle. Créez le
premier Super Admin sans afficher le mot de passe dans l'historique shell :

```sh
read -s ADMIN_PASSWORD
php artisan admin:create-super --name="Nom du propriétaire" --email="admin@boutique.example.tn" --password="$ADMIN_PASSWORD"
unset ADMIN_PASSWORD
```

Conservez ce mot de passe dans un gestionnaire de mots de passe, puis
connectez-vous immédiatement au back-office.

## 10. Worker Laravel obligatoire

Le Scheduler ne traite pas les jobs. Le worker traite les files Redis, y compris
les événements Meta CAPI, les images et les opérations asynchrones.

Installez le template versionné en `root` :

```sh
cp /var/www/ToutDispo/current/deploy/ubuntu/ToutDispo-worker.conf \
  /etc/supervisor/conf.d/ToutDispo-worker.conf
supervisorctl reread
supervisorctl update
supervisorctl status ToutDispo-worker:*
```

La réponse attendue contient `RUNNING`. Il doit exister **un seul mécanisme de
worker** : ce worker Supervisor. N'ajoutez pas le fallback cron de shared
hosting sur ce VPS.

Le worker consomme exactement les queues `meta`, `integrations`, `critical`,
`default`, `media` et `exports`. Après un déploiement, `php artisan
queue:restart` lui demande de terminer le job courant puis Supervisor le relance
avec le nouveau code. Ne lancez jamais `queue:work` à la main en parallèle.

Pour voir le worker en direct :

```sh
tail -f /var/log/supervisor/ToutDispo-worker.log
```

## 11. Scheduler Laravel obligatoire

Le Scheduler lance le heartbeat, la réconciliation de l'outbox Meta, les
purges et les tâches quotidiennes. Installez exactement une entrée cron sous
l'utilisateur `deploy` :

```sh
crontab -u deploy -e
```

Ajoutez cette unique ligne :

```cron
* * * * * cd /var/www/ToutDispo/current && /usr/bin/php artisan schedule:run --no-interaction >> /var/log/passion/scheduler.log 2>&1
```

Préparez le journal puis vérifiez qu'il est unique :

```sh
install -d -o deploy -g www-data -m 0750 /var/log/passion
touch /var/log/passion/scheduler.log
chown deploy:www-data /var/log/passion/scheduler.log
crontab -u deploy -l
```

Ne lancez pas aussi `schedule:work` en service systemd : cron est le seul
déclencheur Scheduler retenu par cette installation.

Vérifiez également, en `root`, que le Scheduler n'est pas déclaré une seconde
fois dans `/etc/cron.d` ou dans une unité systemd :

```sh
cd /var/www/ToutDispo/current
DEPLOY_USER=deploy sh scripts/verify-ubuntu-operations.sh /var/www/ToutDispo/current
```

Ce script ne configure rien : il vérifie que cron est actif, qu'il n'existe
qu'un seul déclencheur Scheduler, que le worker Supervisor est `RUNNING`, que
la rotation des logs est valide, que le stockage est inscriptible, que l'espace
disque n'est pas critique et que les contrôles Laravel opérationnels passent.

## 12. Logs, rotation et sauvegardes

Installez la configuration de rotation existante :

```sh
cp /var/www/ToutDispo/current/deploy/ubuntu/ToutDispo-logrotate.conf \
  /etc/logrotate.d/ToutDispo
logrotate --debug /etc/logrotate.d/ToutDispo
```

Laravel gère ses propres logs journaliers pendant 21 jours. Logrotate ne doit
pas cibler `storage/logs/laravel-*.log`.

Créez un répertoire de sauvegardes non public :

```sh
install -d -o deploy -g www-data -m 0750 /var/backups/ToutDispo
```

Configurez la sauvegarde quotidienne et le test de restauration de la
[section 15](#15-sauvegardes-automatiques-et-test-de-restauration). Les
sauvegardes et les images produit permanentes ne doivent jamais être supprimées
par le pruneur applicatif.

## 13. Connecter GitHub Actions au VPS

Créez une deuxième paire de clés SSH dédiée à GitHub Actions. Ajoutez sa clé
publique à `/home/deploy/.ssh/authorized_keys`, puis ajoutez la clé privée dans
GitHub : dépôt → **Settings** → **Secrets and variables** → **Actions**.

Ajoutez ces secrets :

| Secret | Valeur |
|---|---|
| `SSH_HOST` | IP ou nom d'hôte du VPS |
| `SSH_PORT` | `22` sauf port SSH personnalisé |
| `SSH_USER` | `deploy` |
| `SSH_KEY` | clé privée GitHub Actions → VPS |
| `DEPLOY_PATH` | `/var/www/ToutDispo/current` |
| `VITE_SENTRY_DSN` | DSN navigateur Sentry, seulement si le suivi navigateur est activé |

Les variables `VITE_*` sont intégrées au JavaScript au moment du build GitHub
Actions. Les valeurs `VITE_SENTRY_*` du fichier `.env` du VPS sont donc utiles
uniquement pour un build d'urgence exécuté sur le VPS ; pour le déploiement
normal, renseignez `VITE_SENTRY_DSN` dans les secrets GitHub Actions.

Le workflow `.github/workflows/ci.yml` construit Vite sur GitHub Actions,
prépare le code sur le VPS, copie `public/build`, puis exécute
`scripts/production-optimize.sh`. À chaque push sur `main`, il fait donc :

1. `git pull --ff-only origin main` ;
2. `composer install --no-dev --prefer-dist --optimize-autoloader` ;
3. upload des assets Vite ;
4. `migrate --force` ;
5. `optimize:clear` puis `optimize` ;
6. `queue:restart` pour que Supervisor recharge le code ;
7. `schedule:interrupt` sans démarrer un second Scheduler.

Ne placez jamais `queue:work` dans le script de déploiement : Supervisor est
responsable de la durée de vie du worker.

## 14. Vérification finale avant ouverture publique

Exécutez, dans cet ordre :

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
supervisorctl status
redis-cli ping
curl --fail --silent --show-error https://boutique.example.tn/up
curl --fail --silent --show-error https://boutique.example.tn/api/health/ready
```

Ensuite vérifiez manuellement :

1. accueil, catalogue et produit sur mobile et desktop ;
2. ajout au panier et modification de quantité ;
3. checkout de test et création d'une commande ;
4. connexion Super Admin ;
5. upload d'une image produit ;
6. création d'un produit et d'une catégorie ;
7. état `RUNNING` du worker Supervisor ;
8. journal Scheduler qui s'actualise chaque minute ;
9. absence de jobs échoués ;
10. Meta en mode Test seulement, avec un événement serveur accepté.

Consultez aussi [`../runbooks/post-deploy-smoke-test.md`](../runbooks/post-deploy-smoke-test.md),
[`../runbooks/meta-production-validation.md`](../runbooks/meta-production-validation.md)
et [`ubuntu-operational-retention.md`](ubuntu-operational-retention.md).

## Dépannage rapide

| Symptôme | Vérification immédiate |
|---|---|
| Site 502 | `systemctl status <SERVICE_PHP_FPM> nginx` puis `tail -n 100 /var/log/nginx/error.log` |
| Site 500 | `tail -n 100 storage/logs/laravel-*.log`; ne pas activer `APP_DEBUG` publiquement |
| Images 404 | `php artisan storage:link`, puis droits sur `storage/app/public` |
| Meta « En attente » | `supervisorctl status`, puis `php artisan queue:restart`; vérifier que le worker consomme la queue `meta` |
| Scheduler indisponible | `crontab -u deploy -l`, `systemctl status cron`, puis `php artisan schedule:run` |
| Sessions déconnectées | `redis-cli ping`, vérifier `SESSION_DRIVER=redis` et `SESSION_SECURE_COOKIE=true` sous HTTPS |
| Déploiement GitHub échoue | contrôler les cinq secrets GitHub et que `deploy` peut faire `git pull origin main` |

## 15. Sauvegardes automatiques et test de restauration

Une sauvegarde exploitable contient au minimum :

- la base MariaDB ;
- `storage/app` (images publiques et fichiers privés) ;
- le fichier `.env` chiffré ou protégé ;
- une copie hors du VPS (stockage objet, autre serveur ou sauvegarde gérée par
  le fournisseur).

### 15.1 Créer un compte MariaDB de sauvegarde

En `root`, créez un compte dédié. Remplacez le mot de passe par une valeur
aléatoire conservée dans un gestionnaire de mots de passe :

```sh
mysql -u root -p
```

```sql
CREATE USER 'passion_backup'@'127.0.0.1' IDENTIFIED BY 'MOT_DE_PASSE_SAUVEGARDE_LONG';
GRANT SELECT, SHOW VIEW, TRIGGER, EVENT, LOCK TABLES ON ToutDispo.* TO 'passion_backup'@'127.0.0.1';
FLUSH PRIVILEGES;
EXIT;
```

Créez ensuite `/home/deploy/.my.cnf` avec les droits stricts :

```ini
[client]
host=127.0.0.1
user=passion_backup
password=MOT_DE_PASSE_SAUVEGARDE_LONG
```

```sh
chown deploy:deploy /home/deploy/.my.cnf
chmod 600 /home/deploy/.my.cnf
```

### 15.2 Installer le script de sauvegarde quotidien

En `root`, créez `/usr/local/sbin/ToutDispo-backup` avec ce contenu :

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

Puis :

```sh
chmod 750 /usr/local/sbin/ToutDispo-backup
chown root:deploy /usr/local/sbin/ToutDispo-backup
mkdir -p /var/backups/ToutDispo/daily
chown -R deploy:www-data /var/backups/ToutDispo
chmod 750 /var/backups/ToutDispo
sudo -u deploy /usr/local/sbin/ToutDispo-backup
find /var/backups/ToutDispo/daily -maxdepth 2 -type f -print
```

Ajoutez ensuite cette tâche sous `deploy` à **00:30** chaque jour :

```cron
30 0 * * * /usr/local/sbin/ToutDispo-backup >> /var/log/passion/backup.log 2>&1
```

Cette sauvegarde locale protège contre une erreur applicative, pas contre la
perte du VPS. Copiez chaque sauvegarde vers un stockage hors site chiffré et
mettez en place la rétention métier : 7 quotidiennes, 4 hebdomadaires et 6
mensuelles. Vérifiez chaque semaine que cette copie hors site existe.

### 15.3 Tester une restauration chaque mois

Ne restaurez jamais par-dessus la base de production pour faire un test.

1. Créez une base temporaire, par exemple `passion_restore_test`.
2. Copiez la dernière sauvegarde dans un répertoire temporaire lisible seulement
   par l'administrateur.
3. Vérifiez les sommes : `sha256sum -c SHA256SUMS`.
4. Importez la base temporaire :

   ```sh
   gunzip -c database.sql.gz | mysql -u root -p passion_restore_test
   ```

5. Extrayez `application-data.tar.gz` dans un dossier temporaire, jamais dans
   le répertoire de production.
6. Vérifiez que les tables, images et fichiers privés attendus existent.
7. Notez la date, la durée et le résultat du test dans le journal d'exploitation.
8. Supprimez ensuite la base et le répertoire temporaires.

Une restauration testée mensuellement est obligatoire avant de considérer les
sauvegardes comme fiables.

## 16. Mises à jour de sécurité et maintenance régulière

Activez les mises à jour de sécurité automatiques en `root` :

```sh
apt install -y unattended-upgrades update-notifier-common
dpkg-reconfigure -plow unattended-upgrades
systemctl status unattended-upgrades
```

Une fois par mois, pendant une fenêtre de maintenance :

```sh
apt update
apt list --upgradable
apt upgrade -y
test -f /var/run/reboot-required && echo "Redémarrage requis" || true
systemctl status nginx mariadb redis-server supervisor cron <SERVICE_PHP_FPM>
supervisorctl status
```

Si un redémarrage est requis, prévenez les responsables, vérifiez qu'une
sauvegarde récente existe, redémarrez pendant une fenêtre calme, puis répétez
la [section 14](#14-vérification-finale-avant-ouverture-publique).

Chaque mois, contrôlez aussi :

```sh
cd /var/www/ToutDispo/current
composer audit
php artisan maintenance:prune-operational-data --dry-run
df -h
du -sh storage/logs storage/app /var/backups/ToutDispo
```

Les mises à jour de dépendances applicatives ne se font pas directement sur le
VPS : elles doivent passer par une branche, la CI, les tests et un déploiement
GitHub Actions normal.

## Ce que ce guide ne fait pas automatiquement

- Il ne crée pas le domaine DNS ni le compte Meta/Sentry.
- Il ne remplit pas le catalogue, le contenu, les politiques ou les comptes
  administrateurs métier.
- Il ne crée pas les sauvegardes hors site : elles doivent être configurées et
  testées par le propriétaire.
- Il ne passe pas Meta en Production sans validation propriétaire.
