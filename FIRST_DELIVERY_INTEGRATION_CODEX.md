# Intégration First Delivery — Documentation + Prompt Codex

## 1. Contexte

Le projet possède déjà une intégration **Navex** dans le module **Livraison**.

L'objectif est d'ajouter **First Delivery** comme deuxième société de livraison **sans supprimer, écraser, remplacer ou casser l'intégration Navex existante**.

L'intégration First Delivery doit reprendre au maximum :

- la structure existante du module Livraison ;
- le style UI/UX de la page Navex ;
- les conventions du projet ;
- les services, patterns et composants réutilisables déjà présents.

Le résultat attendu est un module Livraison multi-transporteurs avec au minimum :

- Navex, déjà existant et inchangé ;
- First Delivery, nouvellement intégré.

---

# 2. Règle principale : ne pas casser Navex

L'intégration Navex actuelle doit continuer à fonctionner exactement comme avant.

Il est interdit de :

- supprimer les fonctionnalités Navex ;
- remplacer Navex par First Delivery ;
- modifier inutilement les endpoints ou services Navex ;
- casser les configurations Navex déjà enregistrées ;
- modifier le comportement métier Navex sans nécessité ;
- réutiliser des champs Navex pour stocker des informations First Delivery si cela crée des conflits.

Les modifications doivent être **additives** et compatibles avec l'existant.

---

# 3. Dashboard Livraison

La page Livraison doit permettre à l'administrateur de choisir la société de livraison qu'il souhaite configurer/utiliser.

Exemple d'interface :

```text
Livraison

[ Navex ]    [ First Delivery ]
```

## 3.1 Navex

Quand l'administrateur sélectionne **Navex** :

- afficher l'interface Navex actuelle ;
- conserver tous les champs actuels ;
- conserver les règles de livraison actuelles ;
- conserver le suivi des expéditions Navex ;
- conserver les modes d'envoi existants ;
- ne modifier son fonctionnement que si cela est strictement nécessaire pour supporter plusieurs providers.

## 3.2 First Delivery

Quand l'administrateur sélectionne **First Delivery**, afficher une configuration ayant le même niveau de finition et le même langage visuel que Navex.

Exemple :

```text
Configuration First Delivery

Mode d'envoi

( ) Désactivé
( ) Manuel
( ) Automatique

Adresse API
https://www.firstdeliverygroup.com/api/v2

Token First Delivery
************************************

[ Enregistrer la configuration ]
```

---

# 4. Token First Delivery

## Règle obligatoire

Le token First Delivery doit être ajouté **par l'administrateur depuis le dashboard**.

Le token ne doit PAS être :

- écrit directement dans le code ;
- ajouté manuellement dans `.env` comme méthode principale de configuration ;
- exposé dans le JavaScript frontend ;
- retourné en clair par une API frontend ;
- affiché en clair après sauvegarde ;
- écrit en clair dans les logs.

Flux attendu :

```text
Administrateur
      ↓
Dashboard
      ↓
Livraison
      ↓
First Delivery
      ↓
Saisir le token
      ↓
Enregistrer
      ↓
Stockage sécurisé en base
      ↓
Backend récupère/déchiffre le token
      ↓
Appels API First Delivery
```

Le token doit être stocké en base de données en utilisant le mécanisme de chiffrement déjà utilisé par le projet s'il existe.

Avec Laravel, utiliser de préférence le mécanisme de chiffrement natif ou les casts chiffrés si cela correspond à l'architecture actuelle.

Après sauvegarde, afficher seulement une valeur masquée du type :

```text
••••••••••••••••••••
```

Prévoir la possibilité de :

- ajouter le token ;
- remplacer le token ;
- supprimer/désactiver la configuration First Delivery ;
- tester la configuration si cela peut être fait proprement avec un endpoint non destructif.

---

# 5. Modes d'envoi First Delivery

Reprendre le fonctionnement de Navex autant que possible.

## Désactivé

Aucune commande n'est envoyée vers First Delivery.

## Manuel

Une commande éligible possède une action :

```text
Envoyer à First Delivery
```

L'administrateur décide quand créer l'expédition.

## Automatique

Lorsqu'une commande atteint le statut métier déjà utilisé par le projet pour indiquer qu'elle est confirmée/prête à être expédiée, l'expédition First Delivery peut être créée automatiquement.

Ne pas inventer un nouveau workflow métier si le projet en possède déjà un.

---

# 6. API First Delivery

Base API :

```text
https://www.firstdeliverygroup.com/api/v2
```

Toutes les requêtes authentifiées utilisent :

```http
Authorization: Bearer {TOKEN_ENREGISTRE_DANS_LE_DASHBOARD}
Accept: application/json
Content-Type: application/json
```

Le backend doit utiliser le token enregistré dans la configuration administrateur.

---

# 7. Localités First Delivery

Endpoint :

```http
GET /localities
```

Exemple de réponse :

```json
{
  "status": 200,
  "isError": false,
  "message": "Localities retrieved successfully",
  "result": [
    {
      "locality_id": 1,
      "locality_name": "Ain Drahem",
      "delegation_name": "Ain Drahem",
      "governorate_name": "Jendouba"
    }
  ]
}
```

`locality_id` doit être pris en charge et considéré comme requis pour les nouvelles expéditions.

L'intégration doit :

- récupérer les localités supportées ;
- éviter de faire un appel distant inutile à chaque rendu de page ;
- utiliser cache ou synchronisation locale si cela correspond à l'architecture du projet ;
- associer correctement la localité First Delivery à l'adresse de livraison.

---

# 8. Création d'une commande First Delivery

Endpoint :

```http
POST /create
```

Exemple de payload :

```json
{
  "Client": {
    "nom": "Nom client",
    "locality_id": 1,
    "gouvernerat": "Nabeul",
    "ville": "Nabeul",
    "adresse": "Adresse client",
    "telephone": "55123456",
    "telephone2": ""
  },
  "Produit": {
    "prix": 89,
    "designation": "Commande #1532",
    "nombreArticle": 2,
    "commentaire": "",
    "article": "Nom produit",
    "nombreEchange": 0,
    "estFragile": "non",
    "ouvrirColis": "non"
  }
}
```

## Mapping attendu

```text
Projet                         First Delivery
---------------------------------------------------------
Nom du client             ->   Client.nom
Localité                  ->   Client.locality_id
Gouvernorat               ->   Client.gouvernerat
Ville                     ->   Client.ville
Adresse                   ->   Client.adresse
Téléphone                 ->   Client.telephone
Téléphone secondaire      ->   Client.telephone2

Montant à encaisser       ->   Produit.prix
Référence commande        ->   Produit.designation
Nombre total d'articles   ->   Produit.nombreArticle
Commentaire               ->   Produit.commentaire
Résumé produit            ->   Produit.article
Échange                   ->   Produit.nombreEchange
Fragile                   ->   Produit.estFragile
Ouverture colis           ->   Produit.ouvrirColis
```

Le mapping exact doit être adapté au schéma réel du projet après inspection des modèles et tables existants.

---

# 9. Sauvegarde de l'expédition

Après création réussie chez First Delivery, sauvegarder dans la base locale les informations nécessaires au suivi.

Au minimum :

```text
provider = first_delivery
order_id
barcode
remote_status / remote_state
local_status
label_url ou print_url si fourni
last_synced_at
last_error
created_at
updated_at
```

Ne pas dupliquer les données si le système d'expédition existant possède déjà une structure générique compatible.

Avant de créer de nouvelles tables, inspecter les tables et modèles Navex existants afin de réutiliser proprement les abstractions déjà présentes.

---

# 10. Éviter les doublons d'expédition

Une commande ne doit pas être envoyée plusieurs fois accidentellement à First Delivery.

Avant un appel de création :

- vérifier si une expédition First Delivery existe déjà pour cette commande ;
- désactiver le bouton pendant la requête ;
- gérer les doubles clics ;
- gérer les retries ;
- enregistrer le résultat dès qu'il est confirmé.

En mode automatique, la logique doit être idempotente autant que possible côté application.

---

# 11. Bordereau First Delivery

Après création d'une expédition, récupérer et conserver le lien de bordereau/print retourné par First Delivery lorsqu'il est disponible.

Dans la page commande, fournir une action :

```text
[ Imprimer le bordereau First Delivery ]
```

Le bouton ne doit apparaître que lorsqu'une expédition First Delivery valide possède les informations nécessaires.

Le bordereau First Delivery ne doit pas être remplacé par un PDF local inventé si l'API fournit déjà le document officiel.

---

# 12. Suivi du statut

Endpoint :

```http
POST /etat
```

Payload :

```json
{
  "barCode": "683377360858"
}
```

Le statut retourné par First Delivery doit être synchronisé avec l'expédition locale.

États documentés à prendre en charge :

```text
0   En attente
1   En cours
2   Livré
3   Echange
5   Retour Expéditeur
6   Supprimé
7   Rtn client/agence
8   Au magasin
11  Rtn dépôt
20  A vérifier
30  Retour reçu
31  Rtn définitif

100 Demande d'enlèvement
101 Demande d'enlèvement assignée
102 En cours d'enlèvement
103 Enlevé
104 Demande d'enlèvement annulé

201 Retour assigné
202 Retour en cours d'expédition
203 Retour enlevé
204 Retour Annulé
```

Créer un mapping propre entre :

- état First Delivery ;
- état d'expédition interne ;
- éventuellement état métier de la commande.

Ne jamais modifier automatiquement le statut métier d'une commande sans correspondance explicitement définie.

---

# 13. Synchronisation des statuts

La documentation publique ne fournit pas de webhook à utiliser pour recevoir automatiquement les changements de statut.

Prévoir donc une synchronisation côté backend.

Possibilités :

- bouton manuel `Actualiser le statut` ;
- job/queue Laravel ;
- commande Artisan planifiée ;
- Scheduler Laravel.

Respecter les limitations de fréquence de l'API.

Pour `/etat` :

```text
environ 1 requête / seconde / utilisateur
```

Pour `/filter` :

```text
2 requêtes / 10 secondes
```

Éviter une boucle agressive qui pourrait provoquer un blocage temporaire.

Synchroniser prioritairement les expéditions non terminales.

Les statuts finaux tels que livré, retour définitif ou annulé n'ont généralement pas besoin d'être interrogés continuellement.

---

# 14. Page Commande

La page détail d'une commande doit afficher la section livraison correspondant au provider utilisé.

Exemple First Delivery :

```text
Livraison

Société       First Delivery
Barcode       683377360858
Statut        En cours de livraison
Dernière sync 21/08/2026 10:35

[ Actualiser le statut ]
[ Imprimer le bordereau ]
```

Si la commande n'a pas encore été envoyée et que le mode est manuel :

```text
[ Envoyer à First Delivery ]
```

Si la commande utilise Navex :

- conserver l'affichage et les actions Navex existantes.

---

# 15. Liste des commandes

Dans la liste des commandes, permettre d'identifier facilement :

- le transporteur ;
- le statut de livraison ;
- éventuellement le barcode ;
- les erreurs de synchronisation nécessitant une action.

Exemple :

```text
#1052 | First Delivery | En cours
#1053 | Navex          | Livré
#1054 | First Delivery | En attente
```

Ne pas surcharger l'interface si le design actuel utilise déjà badges, filtres ou colonnes de livraison.

---

# 16. Configuration multi-transporteurs

L'architecture doit accepter au minimum :

```text
navex
first_delivery
```

Éviter les conditions dispersées dans tout le projet du type :

```php
if ($provider === 'first_delivery') { ... }
```

si l'architecture actuelle permet une abstraction plus propre.

Favoriser une structure de type :

```text
DeliveryProvider
├── NavexService
└── FirstDeliveryService
```

ou toute abstraction déjà présente dans le projet.

L'objectif est de ne pas créer une architecture incompatible avec Navex.

---

# 17. Service First Delivery

Créer un service dédié, par exemple :

```text
FirstDeliveryService
```

Il peut exposer des méthodes telles que :

```text
getLocalities()
createOrder()
getOrderStatus()
filterOrders()
cancelOrders()
createPickup()
printPickup()
```

Implémenter uniquement les méthodes nécessaires au scope actuel, mais garder une structure extensible.

Ne jamais faire les appels First Delivery directement depuis les composants Vue/React du frontend.

Flux obligatoire :

```text
Frontend
   ↓
Backend Laravel
   ↓
FirstDeliveryService
   ↓
First Delivery API
```

---

# 18. Annulation

Endpoint :

```http
POST /cancel-orders
```

Exemple :

```json
{
  "barCodes": [
    "111111111111"
  ]
}
```

First Delivery indique que seules les commandes encore en attente peuvent être annulées.

Si l'annulation est exposée dans l'interface :

- vérifier l'état avant d'afficher/autoriser l'action ;
- demander une confirmation côté UI ;
- gérer les erreurs API ;
- ne pas considérer la commande comme annulée localement avant confirmation First Delivery.

---

# 19. Bulk create

First Delivery propose :

```http
POST /bulk-create
```

avec un maximum de 100 commandes par appel et une limitation de fréquence.

Cette fonctionnalité peut être préparée dans l'architecture mais elle n'est pas obligatoire pour la première implémentation si le workflow actuel Navex fonctionne commande par commande.

Ne pas complexifier inutilement la première version.

---

# 20. Pickup / demande d'enlèvement

First Delivery fournit :

```http
POST /pickup
```

Payload :

```json
{
  "barCodes": [
    "123456789012",
    "123456789013"
  ]
}
```

Ainsi que :

```http
POST /request-print/{pickupId}
```

pour imprimer la décharge d'enlèvement.

Cette fonctionnalité peut être intégrée si elle correspond au scope déjà présent dans le module Livraison.

Ne pas la confondre avec le bordereau individuel d'une commande.

---

# 21. Gestion des erreurs

Toutes les erreurs First Delivery doivent être gérées proprement.

Exemples :

- token absent ;
- token invalide ;
- API indisponible ;
- timeout ;
- données client invalides ;
- locality_id manquant ;
- montant non accepté ;
- barcode inconnu ;
- rate limit ;
- commande déjà envoyée ;
- erreur d'impression du bordereau.

L'interface doit afficher un message compréhensible à l'administrateur.

Ne jamais afficher :

- stack trace ;
- token ;
- données sensibles ;
- réponse technique brute inutile.

Conserver éventuellement une erreur technique exploitable dans les logs, sans secret.

---

# 22. Sécurité

Obligatoire :

- token stocké côté serveur ;
- token ajouté via dashboard ;
- token jamais exposé au frontend ;
- token masqué après sauvegarde ;
- validation des permissions administrateur ;
- requêtes API uniquement depuis Laravel ;
- validation serveur de toutes les données ;
- protection CSRF des formulaires internes ;
- logs sans secrets ;
- chiffrement du token en base quand possible.

---

# 23. Migrations et données existantes

Avant toute migration :

1. inspecter la structure actuelle ;
2. inspecter les migrations liées à Navex ;
3. inspecter les modèles ;
4. inspecter les seeders ;
5. inspecter les configurations de livraison existantes.

Les migrations doivent préserver toutes les données existantes.

Ne jamais utiliser dans l'implémentation une migration destructrice ou un `migrate:fresh`.

Si une table générique de configuration/expédition existe déjà, la privilégier.

---

# 24. UI / UX

La nouvelle interface First Delivery doit rester cohérente avec la page Navex actuelle.

Réutiliser :

- cartes ;
- tabs ;
- formulaires ;
- spacing ;
- typographie ;
- boutons ;
- badges ;
- tableaux ;
- messages d'erreur ;
- états de chargement.

Ne pas redesign toute la page Livraison.

Le changement attendu est principalement :

```text
Avant
Livraison Navex

Après
Livraison
├── Navex
└── First Delivery
```

Chaque provider possède sa configuration et son suivi sans interférer avec l'autre.

---

# 25. Critères d'acceptation

L'implémentation est considérée comme terminée lorsque :

- [ ] Navex fonctionne toujours comme avant.
- [ ] Le dashboard permet de choisir Navex ou First Delivery.
- [ ] First Delivery possède sa propre configuration.
- [ ] Le token First Delivery est saisi depuis le dashboard.
- [ ] Le token n'est pas codé dans `.env` comme configuration métier principale.
- [ ] Le token est stocké de façon sécurisée en base.
- [ ] Le token est masqué dans l'interface.
- [ ] Une commande peut être envoyée à First Delivery.
- [ ] Le barcode retourné est enregistré.
- [ ] Le bordereau First Delivery peut être ouvert/imprimé.
- [ ] Le statut First Delivery est récupéré et sauvegardé.
- [ ] Le statut apparaît dans la page commande.
- [ ] Il existe une action de synchronisation du statut.
- [ ] Le mode manuel fonctionne.
- [ ] Le mode automatique fonctionne si ce mode existe côté Navex et peut être repris proprement.
- [ ] Une erreur API ne casse pas la commande locale.
- [ ] Une commande ne peut pas être envoyée deux fois accidentellement.
- [ ] Les permissions administrateur sont respectées.
- [ ] Aucun secret First Delivery n'est exposé au frontend.
- [ ] Les migrations ne détruisent aucune donnée existante.

---

# 26. Tests recommandés

Ajouter des tests cohérents avec la suite existante.

Au minimum tester :

```text
- sauvegarde de la configuration First Delivery
- token inaccessible aux utilisateurs non autorisés
- token non exposé par les réponses frontend
- création d'une expédition
- sauvegarde du barcode
- prévention des doublons
- erreur API lors de la création
- récupération du statut
- mapping des statuts
- impression/lien du bordereau
- fonctionnement Navex non régressé
```

Mocker les appels HTTP First Delivery dans les tests.

Ne pas effectuer de vrais appels réseau dans les tests automatiques.

---

# 27. Documentation API de référence

Base :

```text
https://www.firstdeliverygroup.com/api/v2
```

Endpoints utiles :

```text
GET  /localities
POST /create
POST /bulk-create
POST /etat
POST /filter
POST /cancel-orders
POST /pickup
POST /request-print/{pickupId}
```

Authentification :

```http
Authorization: Bearer {token enregistré depuis le dashboard}
```

---

# PROMPT À DONNER À CODEX

Tu dois intégrer **First Delivery** dans ce projet en respectant strictement l'architecture et le code déjà existants.

## Contexte

Le projet possède déjà une intégration complète **Navex** dans le module Livraison.

Je veux ajouter **First Delivery comme deuxième société de livraison**, sans supprimer, remplacer, écraser ou casser Navex.

La capture/page Navex existante doit servir de référence fonctionnelle et visuelle.

## Avant de coder

Commence obligatoirement par inspecter le projet et identifier :

1. les routes du module Livraison ;
2. les controllers concernés ;
3. les services Navex ;
4. les modèles et tables liés aux expéditions ;
5. les migrations liées à Navex/livraison ;
6. les composants frontend de la page Livraison ;
7. la page détail d'une commande ;
8. le workflow de confirmation d'une commande ;
9. les jobs/queues/scheduler existants ;
10. le système utilisé pour stocker les paramètres sensibles ;
11. les permissions administrateur ;
12. les tests Navex/livraison existants.

Ne crée pas une architecture parallèle avant d'avoir compris et réutilisé l'existant.

## Règle absolue

**Navex doit continuer à fonctionner exactement comme avant.**

Les modifications doivent être additives et backward-compatible.

## Dashboard Livraison

Transformer la page de manière à permettre de sélectionner :

```text
[ Navex ] [ First Delivery ]
```

Quand Navex est sélectionné, conserver l'interface et le comportement existants.

Quand First Delivery est sélectionné, afficher une configuration cohérente avec l'UI Navex.

## Token First Delivery

Le token First Delivery doit obligatoirement être saisi par l'administrateur dans le dashboard.

NE PAS mettre le token directement dans le code.

NE PAS utiliser `.env` comme système principal permettant à l'administrateur de configurer le token.

Flux attendu :

```text
Admin -> Dashboard -> Livraison -> First Delivery
-> saisit le token
-> sauvegarde sécurisée en base
-> backend utilise ce token pour appeler First Delivery
```

Le token doit être chiffré en base en utilisant les mécanismes Laravel/projet déjà disponibles.

Après sauvegarde, il doit être masqué dans l'interface.

Le token ne doit jamais être envoyé au frontend en clair ni apparaître dans les logs.

## API

Utiliser :

```text
https://www.firstdeliverygroup.com/api/v2
```

Authentification :

```http
Authorization: Bearer {token stocké depuis le dashboard}
```

Endpoints nécessaires au scope principal :

```text
GET  /localities
POST /create
POST /etat
```

Ajouter également proprement le support de :

```text
POST /cancel-orders
POST /filter
POST /pickup
POST /request-print/{pickupId}
POST /bulk-create
```

uniquement si cela s'intègre naturellement au module actuel ou si nécessaire au workflow existant.

## Création d'une expédition

Lorsqu'une commande éligible est envoyée à First Delivery :

1. récupérer les données de la commande ;
2. construire le payload attendu ;
3. envoyer `POST /create` ;
4. enregistrer le barcode retourné ;
5. enregistrer le lien de bordereau/print s'il est retourné ;
6. enregistrer le provider `first_delivery` ;
7. enregistrer le statut initial ;
8. gérer les erreurs sans modifier incorrectement la commande locale.

Exemple :

```json
{
  "Client": {
    "nom": "Nom client",
    "locality_id": 1,
    "gouvernerat": "Nabeul",
    "ville": "Nabeul",
    "adresse": "Adresse",
    "telephone": "55123456",
    "telephone2": ""
  },
  "Produit": {
    "prix": 89,
    "designation": "Commande #1532",
    "nombreArticle": 2,
    "commentaire": "",
    "article": "Produits commande",
    "nombreEchange": 0,
    "estFragile": "non",
    "ouvrirColis": "non"
  }
}
```

Adapte le mapping aux vrais modèles du projet.

## Localities

Utiliser :

```text
GET /localities
```

Prendre en charge `locality_id`.

Ne pas appeler inutilement l'API à chaque rendu ; utiliser le mécanisme de cache/synchronisation le plus cohérent avec le projet.

## Bordereau

Après création d'une expédition First Delivery, permettre depuis la commande :

```text
[ Imprimer le bordereau First Delivery ]
```

Utiliser le document/lien officiel retourné par First Delivery lorsqu'il est disponible.

## Suivi

Utiliser :

```text
POST /etat
```

avec :

```json
{
  "barCode": "..."
}
```

Enregistrer le statut distant et afficher le statut de livraison dans la page commande.

Ajouter :

```text
[ Actualiser le statut ]
```

Prévoir également une synchronisation backend périodique si l'architecture actuelle le permet, tout en respectant les rate limits.

Ne pas supposer qu'un webhook First Delivery existe : la documentation fournie n'en expose pas.

## Page commande

Afficher clairement :

```text
Société : First Delivery
Barcode : ...
Statut : ...
Dernière synchronisation : ...

[ Actualiser le statut ]
[ Imprimer le bordereau ]
```

Si le provider est Navex, conserver l'affichage actuel.

## Modes

Reprendre les modes présents sur la page Navex :

```text
Désactivé
Manuel
Automatique
```

Le mode automatique doit se brancher sur le statut métier réellement utilisé par le projet, sans inventer un nouveau workflow.

## Architecture

Créer ou intégrer un `FirstDeliveryService` côté backend.

Aucun appel First Delivery direct depuis le frontend.

Réutiliser une abstraction multi-provider existante si elle existe.

Si elle n'existe pas, effectuer le refactor minimal nécessaire pour obtenir quelque chose de propre du type :

```text
Delivery
├── Navex
└── First Delivery
```

sans réécrire Navex inutilement.

## Base de données

Inspecter l'existant avant de créer des tables.

Réutiliser les structures génériques existantes lorsqu'elles sont adaptées.

Toute migration doit préserver les données actuelles.

Ne jamais exécuter ou proposer une migration destructive.

## Robustesse

Empêcher le double envoi d'une même commande.

Gérer :

- token absent/invalide ;
- timeout ;
- API indisponible ;
- locality manquante ;
- payload invalide ;
- rate limiting ;
- barcode inexistant ;
- bordereau indisponible ;
- erreurs de synchronisation.

Ne pas changer localement un statut tant que l'opération distante n'est pas confirmée.

## Tests

Ajouter des tests avec mocks HTTP couvrant au minimum :

- configuration First Delivery ;
- sécurité du token ;
- création d'expédition ;
- sauvegarde barcode ;
- prévention des doublons ;
- erreurs API ;
- synchronisation du statut ;
- mapping des états ;
- bordereau ;
- non-régression Navex.

## Méthode de travail demandée

1. Inspecte le projet.
2. Donne-moi un résumé très court de l'architecture Navex actuelle et des fichiers qui devront être modifiés.
3. Implémente First Delivery en réutilisant au maximum l'existant.
4. N'écrase aucune fonctionnalité Navex.
5. Exécute les tests/lint disponibles.
6. Corrige les erreurs trouvées.
7. Termine par un récapitulatif des fichiers modifiés, migrations ajoutées et fonctionnalités réalisées.

Ne me demande pas de fournir le token First Delivery pendant le développement : **le token sera saisi par l'administrateur depuis le dashboard une fois la fonctionnalité installée**.
