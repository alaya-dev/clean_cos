# Déployer ToutDispo avec OpenShip

[OpenShip](https://github.com/oblien/openship) est applicable à Passion
Cosmetic parce qu'il peut détecter et déployer un projet Docker Compose. Cette
application fournit donc une image et un `docker-compose.yml` portables.

## Décision recommandée

Pour un premier VPS et une personne non technique, utilisez d'abord le guide
[Docker](docker-vps-guide.md). OpenShip ajoute une plateforme de contrôle, des
comptes administrateurs et un accès au socket Docker. C'est utile pour les
déploiements par push, les prévisualisations et les rollbacks, mais ce n'est pas
une condition de fonctionnement de la boutique.

Utilisez OpenShip seulement sur un hôte de confiance. Sa documentation indique
que le mode Compose auto-hébergé utilise le socket Docker de l'hôte et prend les
ports 80/443 pour son edge. Ne l'installez pas en mode Compose sur le même VPS
que le Nginx hôte du guide Docker, sinon les deux services entreront en conflit
pour les mêmes ports.

## Architecture sûre recommandée

1. Un petit VPS de contrôle OpenShip, avec son propre sous-domaine, par exemple
   `deploy.example.tn`.
2. Un VPS applicatif séparé, préparé exactement avec les sections 1 à 3 du
   [guide Docker](docker-vps-guide.md).
3. OpenShip en mode **bare** sur le VPS de contrôle, connecté par SSH au VPS
   applicatif. Le VPS applicatif garde Docker Compose, Nginx/TLS, MySQL, Redis,
   worker et scheduler.

Ainsi OpenShip ne possède jamais la base de production, les volumes de la
boutique ni les ports publics de la boutique.

## 1. Installer le contrôle OpenShip

Sur le VPS de contrôle uniquement, suivez la documentation officielle OpenShip
pour une installation stable, créez le premier administrateur et protégez son
compte avec un mot de passe unique. Pour une installation sans assistant, la
documentation officielle propose :

```sh
curl -fsSL https://get.openship.io | sh
openship up --bare --public-url https://deploy.example.tn
```

Vérifiez toujours la version et les instructions actuelles sur
[`oblien/openship`](https://github.com/oblien/openship) avant de lancer cette
commande. Ne remplacez pas `--bare` par `--compose` dans cette architecture.

## 2. Donner un accès SSH minimal au VPS applicatif

Sur le VPS applicatif, créez un compte `openship-deploy` limité aux opérations
de déploiement. Ajoutez sa clé publique dans `authorized_keys` puis donnez une
autorisation `sudo` **uniquement** pour le script versionné :

```text
openship-deploy ALL=(deploy) NOPASSWD: /home/deploy/ToutDispo/scripts/docker-deploy.sh
```

Ne donnez pas le mot de passe root, les secrets `.env.docker`, ni un accès
général au groupe Docker au compte de contrôle.

## 3. Créer le projet OpenShip

Dans l'interface OpenShip :

1. créez un projet privé `ToutDispo` ;
2. connectez le dépôt GitHub et sélectionnez la branche `main` ;
3. ajoutez le VPS applicatif comme cible SSH ;
4. définissez `/home/deploy/ToutDispo` comme répertoire de projet ;
5. configurez la commande de déploiement :

   ```sh
   sudo -u deploy /home/deploy/ToutDispo/scripts/docker-deploy.sh
   ```

6. activez le déploiement automatique seulement après un déploiement manuel et
   les vérifications de la section 5 du guide Docker.

OpenShip détectera le `docker-compose.yml`; la commande ci-dessus reste la
source d'autorité pour les migrations, caches, worker et scheduler. N'ajoutez
pas une seconde commande `queue:work` ou `schedule:work` dans OpenShip.

## 4. Ce qu'OpenShip ne remplace pas

OpenShip ne remplace pas :

- les sauvegardes externes MySQL et `app_storage` ;
- le test mensuel de restauration ;
- les mises à jour Ubuntu ;
- la vérification du domaine Meta ;
- les contrôles Super Admin de l'application ;
- le smoke test après chaque déploiement.

Après chaque déploiement, vérifiez `docker compose ps`, `/up`, `/api/health/ready`,
les journaux du worker et du scheduler, ainsi qu'un checkout de test.

## Limites connues

Ce guide ne prétend pas valider OpenShip en production : Docker n'est pas lancé
localement dans ce projet et l'interface OpenShip évolue. Il fournit une
architecture sans conflit de ports et sans couplage de domaine. Avant le premier
déploiement, relisez la documentation officielle et effectuez une répétition sur
un VPS de staging sans données client.
