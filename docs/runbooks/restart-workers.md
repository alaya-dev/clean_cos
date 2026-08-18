# Redémarrer les workers

En déploiement, lancer `php artisan queue:restart` afin que les workers gérés par Supervisor ou systemd terminent leur job courant puis rechargent le nouveau code. Ne pas lancer `queue:work` depuis un script de déploiement : le gestionnaire de processus reste responsable du nombre de workers et des redémarrages. Vérifier `/api/health/ready`, les jobs échoués et la latence; ne pas vider Redis.
