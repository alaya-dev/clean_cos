# Test Meta local

## Configuration de l’URL source

- En local, définir `APP_URL=http://localhost:8000`.
- En staging, définir `APP_URL` avec l’URL HTTPS du staging.
- En production, définir `APP_URL` avec le domaine final HTTPS.
- `META_TEST_EVENT_SOURCE_URL` est facultative. Lorsqu’elle est absente ou vide,
  le test synthétique utilise `APP_URL`.

Aucun domaine de staging n’est codé en dur. Les URL de navigation normales
proviennent de la requête, conservent le port local et excluent la query string
et le fragment.

## Procédure

1. Enregistrer une seule fois l’identifiant Pixel, le jeton CAPI et le code Test Events.
2. Lancer « Tester la connexion serveur » et vérifier `request_sent`, le statut
   HTTP, `events_received`, la version Graph et l’URL source.
3. Quitter puis rouvrir la page Meta.
4. Relancer le test sans ressaisir le jeton. Le test doit réussir avec la même
   configuration active.
5. Naviguer sur l’accueil puis une fiche produit avec le consentement actif.
6. Exécuter le worker `meta` et vérifier séparément PageView et ViewContent dans
   Meta Test Events.

Le jeton n’est jamais renvoyé au navigateur. Un champ vide conserve le jeton
chiffré existant ; sa suppression passe par l’action explicite dédiée.

## Résultats attendus

- noms sortants exacts : `PageView`, `ViewContent`, `Search`, `AddToCart`,
  `InitiateCheckout`, `Purchase` ;
- même `event_name` et même `event_id` pour les copies navigateur et serveur ;
- un bloqueur peut empêcher le Pixel, sans empêcher CAPI ;
- une erreur locale de configuration reste relançable et n’est pas présentée
  comme un refus de Meta ;
- le mode Test transmet le Test Event Code ; le mode Production ne le transmet pas.

Utiliser des HTTP fakes dans les tests automatisés. Les tests réels doivent
rester limités au Pixel et au dataset de test approuvés.
