# Référence d’environnement

Ne jamais copier de valeur de production dans ce document ou dans Git. Après toute modification, exécuter `php artisan config:cache` dans l’environnement visé puis les contrôles de santé.

| Variable | Rôle | Secret | Requise | Environnements | Exemple sûr | Rotation / dépendance |
|---|---|---:|---:|---|---|---|
| `APP_KEY` | chiffrement Laravel | oui | oui | tous | `base64:…` | ne pas modifier sans plan de rotation des données chiffrées |
| `APP_ENV`, `APP_DEBUG`, `APP_URL` | mode, débogage, URLs canoniques | non | oui | tous | `production`, `false`, `https://boutique.example.tn` | `APP_DEBUG=false` en staging/production |
| `DB_*` | MariaDB/MySQL | mot de passe | oui | tous | `ToutDispo` | compte à privilèges minimaux, sauvegarde avant migration |
| `REDIS_*` | sessions, cache, verrous, files, limites | éventuellement | oui | tous | `127.0.0.1:6379` | Redis/Memurai compatible, préfixes isolés par environnement |
| `SESSION_*`, `CACHE_STORE`, `QUEUE_CONNECTION` | état durable opérationnel | non | oui | tous | `redis`, `redis`, `redis` | HTTPS exige cookie Secure; ne pas utiliser sqlite/file en intégration |
| `FILESYSTEM_DISK` | médias publics et privés | parfois | oui | tous | `local` | vérifier droits, sauvegarde et restauration |
| `SENTRY_*` | erreurs, release, environnement | DSN | optionnelle | staging/prod | DSN distinct par env | PII désactivée; rotation si fuite |
| `META_*` / configuration Meta chiffrée | Pixel/CAPI | token | optionnelle | staging/prod | `***` | jamais dans Vue/logs; rotation via runbook |
| `NAVEX_*` / configuration Navex chiffrée | envoi et suivi des colis | identifiants saisis dans l’admin | optionnelle | staging/prod | `https://app.navex.tn` | ne jamais placer les identifiants dans `.env`, logs ou Vue; l’hôte HTTPS est validé par liste blanche |
| `SECURITY_CSP_MODE`, `SECURITY_CSP`, `SECURITY_HSTS_ENABLED` | CSP et HSTS | non | oui | tous | `enforce`, `false` local | passer de report-only à enforce après validation des hôtes; HSTS uniquement HTTPS |
| `MAIL_*` | transport transactionnel futur | mot de passe | optionnelle | tous | `log` local | ne pas exposer les identifiants |

Versions attendues : PHP 8.2+, Node 24 pour la CI/build, MariaDB/MySQL compatible, Redis/Memurai compatible. Les workers `php artisan queue:work redis --queue=critical,meta,integrations,default,media,exports --sleep=1 --tries=5 --timeout=120` et le planificateur `php artisan schedule:work` sont des processus distincts.
