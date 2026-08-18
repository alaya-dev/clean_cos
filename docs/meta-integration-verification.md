# Vérification de l’intégration Meta

## Garanties implémentées

- Le jeton CAPI est chiffré au repos et n’est jamais sérialisé dans l’API admin.
- Une valeur omise ou vide conserve le jeton de la configuration active ; une
  valeur non vide le remplace ; la suppression est une action explicite.
- Les tests synthétiques utilisent `META_TEST_EVENT_SOURCE_URL`, sinon `APP_URL`.
- Les événements normaux utilisent l’URL canonique de la requête sans paramètres.
- Les échecs de configuration ne sont pas classés comme des refus Meta et
  restent relançables après correction, avec le même identifiant d’événement.
- Le navigateur et CAPI sont diagnostiqués séparément.

## Comparaison prudente avec Converty

| Capacité | Comportement Converty connu | ToutDispo | Preuve disponible | Écart restant |
|---|---|---|---|---|
| Simplicité de configuration | Non documenté dans ce dépôt | Carte compacte, jeton saisi une fois | Tests API et UI | Validation client en staging |
| Persistance du jeton | Non vérifiable | Conservation chiffrée, remplacement et suppression explicites | Tests de rechargement et double test | Aucun écart connu côté Laravel |
| Pixel navigateur | Dépendait du navigateur | Pixel consent-aware, blocage distingué | Tests storefront | Réception navigateur à confirmer dans Events Manager |
| CAPI serveur | Non vérifiable | Outbox asynchrone et indépendant du checkout | Tests jobs et HTTP | Test réel staging requis |
| Événements standard | Non vérifiable | Six noms Meta exacts | Test payload | Présentation « Custom event » à confirmer dans l’interface Meta |
| Déduplication | Non vérifiable | `event_name` et `event_id` partagés | Tests payload/idempotence | Confirmation Meta non déductible localement |
| Purchase | Non vérifiable | Un événement par commande, identifiant stable | Tests Purchase existants | Validation réelle staging requise |
| Retry / 429 | Non vérifiable | Retry borné, 429 temporaire, configuration relançable | Tests client/job | Observation worker staging |
| Catalogue | Non vérifiable | Content ID du produit parent et snapshots commande | Tests catalogue | Concordance dataset test à contrôler |
| Diagnostics | Non vérifiable | Canaux séparés, détails assainis, filtres et pagination | Tests API/UI | Retour d’usage administrateur |
| Consentement | Non vérifiable | Éligibilité serveur et navigateur conservée | Tests consentement | Validation juridique hors périmètre |
| Propriété client | Non documenté | Configuration et historique dans ToutDispo | Code et base applicative | Gouvernance Meta externe à confirmer |

Verdict actuel : **impossible à déterminer**. L’intégration ToutDispo est
plus transparente et vérifiable dans le code, mais aucune preuve fiable du
comportement complet de Converty n’est disponible et le staging doit encore être
validé après déploiement.

## Pourquoi cette intégration Meta est plus fiable pour ToutDispo

ToutDispo contrôle directement les identifiants d’événement, les données
catalogue, la file d’attente et les reprises. Le checkout ne dépend jamais de
Meta, un bloqueur navigateur n’empêche pas l’envoi serveur, et chaque échec est
classé sans exposer le jeton ni les données client. Cette transparence facilite
le diagnostic et réduit les doubles achats. La supériorité opérationnelle sur
Converty ne sera toutefois affirmée qu’après validation réelle du staging.

## Non effectué

- aucun catalogue ou dataset Meta de production modifié ;
- aucun raccordement du dataset de test au catalogue de production ;
- aucune modification Converty ;
- aucune campagne ou publicité active modifiée.
