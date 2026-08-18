# Registre des risques de dépendances

À réviser à chaque release et au plus tard le **2026-08-25**. Commandes périodiques : `composer update --dry-run` puis `composer audit`; exécuter aussi `npm audit --audit-level=high` avant toute livraison.

| Package | Version installée | Avis | Fonction affectée | Chemin vulnérable atteignable ? | Correctif | Remédiation bloquée | Contrôles compensatoires | Date revue |
|---|---|---|---|---|---|---|---|---|
| Composer packages | voir `composer.lock` | à relever par `composer audit` | backend | à déterminer à chaque audit | version indiquée par Composer | aucune exception acceptée sans analyse | CI lance audit, mises à jour évaluées à sec | 2026-07-25 |
| `brace-expansion` (via `eslint` → `minimatch`) | 5.0.7 | GHSA-mh99-v99m-4gvg | lint de développement/CI | non : absent du bundle et du serveur de production | 5.0.8 annoncée | le lockfile conserve 5.0.7 et une mise à jour non vérifiée risquerait de modifier la chaîne ESLint ; remédiation à appliquer dès que la résolution compatible est confirmée | dépendance dev uniquement, aucune entrée utilisateur de production ne lui est transmise, CI et lockfile ; `npm audit --omit=dev --audit-level=high` ne remonte aucune vulnérabilité de production | 2026-07-28 |

Une ligne ne peut être clôturée qu’avec l’identifiant exact de l’avis, le chemin réellement atteignable, une version corrigée testée et la preuve de validation. Aucun avis haut/critique atteignable n’est acceptable à la sortie.
