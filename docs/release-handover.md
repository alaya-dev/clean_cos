# Handover de release

Le paquet de livraison comprend : source, documents approuvés, `environment-reference.md`, `runbooks/`, UAT, rapport sécurité/confidentialité/performance, résultats de tests et liste des limites.

## État des validations dépendantes

| Élément | État |
|---|---|
| Catalogue/dataset Meta de production | non modifié |
| Dataset de test Meta | non connecté |
| Converty | non modifié |
| Campagnes/ads | non modifiés |
| Production/DNS | non déployé / non modifié |

Avant release : exécuter CI complète, restauration de sauvegarde, smoke test post-déploiement et UAT propriétaire. La validation Meta live suit uniquement `uat/meta-deferred-checklist.md`.
