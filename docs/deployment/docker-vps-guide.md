# Guide complet : ToutDispo sur un VPS Docker

Ce guide installe ToutDispo sur un VPS Ubuntu vide avec Docker. Il est
volontairement séparé du guide sans Docker : choisissez **un seul** des deux
guides pour un serveur donné.

Il crée exactement ces services :

- `nginx` : le serveur web applicatif ;
- `app` : PHP-FPM/Laravel ;
- `mysql` : la base durable ;
- `redis` : cache, sessions, verrous et queues ;
- `worker` : un worker Laravel qui traite `meta`, `critical`, `default`,
  `media` et `exports` ;
- `scheduler` : l'unique Scheduler Laravel (`schedule:work`) ;
- `migrate` : une tâche ponctuelle exécutée uniquement pendant le déploiement.

N'exécutez ni cron Laravel ni Supervisor sur cet hôte : les conteneurs
`scheduler` et `worker` sont leurs propriétaires uniques.

## 1. Préparer le VPS

Utilisez le compte `ubuntu` déjà fourni par votre hébergeur. Ne créez pas de
compte `deploy` séparé et n'exécutez pas l'application en `root`. Remplacez les
exemples par votre vrai domaine :

```sh
sudo apt update && sudo apt upgrade -y
sudo apt install -y ca-certificates curl git nginx certbot python3-certbot-nginx ufw
curl -fsSL https://get.docker.com | sudo sh
sudo apt install -y docker-compose-plugin
sudo systemctl enable --now docker nginx
docker --version
docker compose version
```

Ajoutez `ubuntu` au groupe Docker puis reconnectez-vous afin que les commandes
Docker puissent être exécutées sans `sudo` :

```sh
sudo usermod -aG docker ubuntu
```

Activez le pare-feu :

```sh
sudo ufw allow OpenSSH
sudo ufw allow 'Nginx Full'
sudo ufw --force enable
```

## 2. Récupérer le projet et préparer les secrets

Ajoutez une clé GitHub en lecture seule pour le VPS, puis exécutez les commandes
ci-dessous en tant que `ubuntu` :

```sh
git clone git@github.com:VOTRE_COMPTE/VOTRE_DEPOT.git /home/ubuntu/ToutDispo
cd /home/ubuntu/ToutDispo
git checkout main
cp .env.docker.example .env.docker
chmod 600 .env.docker
```

Générez une clé Laravel sans l'écrire dans Git :

```sh
docker run --rm php:8.4-cli php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"
```

Ouvrez `.env.docker` et remplacez toutes les valeurs `REPLACE_...`. Conservez
les trois mots de passe MySQL/Redis dans un gestionnaire de mots de passe.
Utilisez votre domaine HTTPS réel dans `APP_URL`.

Les identifiants Meta ne vont pas dans ce fichier : ils sont saisis uniquement
dans **Suivi Meta**, puis chiffrés par Laravel.

## 3. Configurer HTTPS devant Docker

L'application Docker écoute seulement sur `127.0.0.1:8080`. Nginx installé sur
l'hôte gère le domaine et TLS.

Dans `.env.docker`, définissez :

```dotenv
HTTP_PORT=127.0.0.1:8080
```

Créez `/etc/nginx/sites-available/ToutDispo` en `root` :

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name boutique.example.tn www.boutique.example.tn;
    # Laravel limits each product image to 2 MB. This allows multipart
    # editor saves containing several images without a host-level 413.
    client_max_body_size 256m;

    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_http_version 1.1;
    }
}
```

Activez-le après avoir remplacé le domaine :

```sh
ln -s /etc/nginx/sites-available/ToutDispo /etc/nginx/sites-enabled/ToutDispo
rm -f /etc/nginx/sites-enabled/default
nginx -t
systemctl reload nginx
certbot --nginx -d boutique.example.tn -d www.boutique.example.tn
```

Après le premier certificat valide, passez `SECURITY_CSP_MODE=enforce` et
`SECURITY_HSTS_ENABLED=true` dans `.env.docker`, puis redéployez.

## 4. Premier déploiement

En tant que `ubuntu` :

```sh
cd /home/ubuntu/ToutDispo
chmod 755 scripts/docker-deploy.sh docker/php/entrypoint.sh
sh scripts/docker-deploy.sh
```

Ce script démarre MySQL/Redis, construit l'image et les assets Vite, exécute les
migrations et caches Laravel une seule fois, puis recrée l'application, le
worker, le scheduler et Nginx. Il ne démarre jamais un second worker ou
Scheduler.

Créez le premier Super Admin sans inscrire son mot de passe dans l'historique :

```sh
read -rsp "Mot de passe Super Admin : " ADMIN_PASSWORD; echo
docker compose --env-file .env.docker exec app php artisan admin:create-super \
  --name="Nom du propriétaire" --email="admin@boutique.example.tn" --password="$ADMIN_PASSWORD"
unset ADMIN_PASSWORD
```

## 5. Vérifier que tout fonctionne

```sh
cd /home/ubuntu/ToutDispo
docker compose --env-file .env.docker ps
docker compose --env-file .env.docker logs --tail=100 worker
docker compose --env-file .env.docker logs --tail=100 scheduler
curl --fail --silent --show-error https://boutique.example.tn/up
curl --fail --silent --show-error https://boutique.example.tn/api/health/ready
docker compose --env-file .env.docker exec app php artisan schedule:list
docker compose --env-file .env.docker exec app php artisan maintenance:prune-operational-data --dry-run
```

Les services `app`, `worker`, `scheduler`, `mysql`, `redis` et `nginx` doivent
être `running` ou `healthy`. Faites ensuite le smoke test de boutique, panier,
checkout, image produit, connexion Super Admin et Meta en mode Test.

## 6. Déployer une nouvelle version

Chaque déploiement manuel est seulement :

```sh
cd /home/ubuntu/ToutDispo
git pull --ff-only origin main
sh scripts/docker-deploy.sh
```

Le worker et le scheduler ont `restart: unless-stopped`. Après un redémarrage du
VPS ou un crash, Docker les relance automatiquement. Les événements Meta non
livrés restent dans MySQL et la tâche `meta:requeue-pending` les remet en queue.

Ne lancez pas `php artisan queue:work`, `schedule:work`, cron Laravel ou
Supervisor manuellement sur cet hôte : cela créerait des consommateurs doubles.

## 7. Sauvegardes et mises à jour

Les volumes Docker contiennent les données :

- `mysql_data` : base de données ;
- `app_storage` : images produit et fichiers privés ;
- `redis_data` : données Redis persistées.

Sauvegardez au moins MySQL et `app_storage` chaque jour vers un emplacement hors
du VPS, puis faites une restauration dans une base et un volume temporaires
chaque mois. Le guide sans Docker explique la politique minimale : 7
quotidiennes, 4 hebdomadaires et 6 mensuelles, avec test de restauration.

Activez `unattended-upgrades` et, mensuellement, appliquez les mises à jour
Ubuntu dans une fenêtre de maintenance. Après un reboot, vérifiez à nouveau
`docker compose ps`, l'URL `/up`, le worker, le scheduler et une sauvegarde
récente.

## 8. Dépannage sans supprimer de données

```sh
docker compose --env-file .env.docker logs --tail=200 app
docker compose --env-file .env.docker logs --tail=200 worker
docker compose --env-file .env.docker logs --tail=200 scheduler
docker compose --env-file .env.docker exec app php artisan about
docker compose --env-file .env.docker exec app php artisan queue:failed
```

N'utilisez jamais `docker compose down -v` sur une boutique : l'option `-v`
supprime les volumes et donc la base, Redis et les fichiers stockés.
