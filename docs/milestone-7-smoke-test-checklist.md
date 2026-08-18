# Milestone 7 — smoke test manuel

Utiliser uniquement l’environnement local ou staging, avec des données synthétiques. Ne jamais exécuter `migrate:fresh` ni ce seed sur la base de production. Le seed remplit les tables métier et relationnelles ; les tables transitoires de framework (`sessions`, cache, jobs, failed jobs et password reset tokens) restent volontairement vides.

## Préparation

- [ ] MariaDB et Redis sont démarrés.
- [ ] Les migrations sont à jour : `php artisan migrate --force`.
- [ ] Le jeu de démonstration est chargé : `php artisan db:seed --class=DemoPlatformSeeder --force`.
- [ ] Le back-office est accessible avec un Super Admin local. Le seed local crée `ademhajjej@gmail.com` avec le même mot de passe ; changer ce mot de passe hors environnement local.
- [ ] Vérifier que le tableau de bord affiche 100 commandes, des réclamations, du stock faible et des diagnostics Meta synthétiques.

## Storefront et catalogue

- [ ] Accueil en français, rendu serveur, sans barre de défilement horizontale à 320, 390, 768, 1024 et 1440 px.
- [ ] Bandeau d’annonce, en-tête, héros, catégories circulaires, produits, tuiles, éditorial, réassurance, galerie, contenu de marque et footer apparaissent dans cet ordre.
- [ ] Le carrousel peut être contrôlé au clavier ; pause au focus/survol ; pas d’autoplay avec « réduire les animations ».
- [ ] Le premier visuel héro est prioritaire et les suivants sont différés.
- [ ] Recherche : état de chargement, résultat, aucun résultat, fermeture mobile et navigation clavier.
- [ ] Catalogue : filtres, tri, pagination, catégories et prix en DT fonctionnent.
- [ ] Fiche produit : galerie, variantes pré-sélectionnées lorsqu’elles sont disponibles, changement d’image de variante, disponibilité et ajout au panier.

## Panier et checkout

- [ ] Le drawer du panier s’ouvre immédiatement avec les articles locaux ; une réouverture chaude ne montre pas de chargement complet.
- [ ] Ajout, retrait et changement de quantité mettent immédiatement à jour le badge, la ligne, le sous-total et le total.
- [ ] La livraison reste lisible pendant la réconciliation et se met à jour seulement si la règle serveur l’exige.
- [ ] Le drawer respecte backdrop, Échap, restauration du focus et verrouillage de scroll.
- [ ] La page panier est lisible sur mobile et desktop ; les clics rapides sur `+`/`−` conservent la dernière quantité demandée.
- [ ] Checkout : champs système et champs configurables, validation française, code promo et suppression d’un code invalide.
- [ ] Une commande COD de test crée une confirmation signée, un snapshot des lignes/champs et une seule commande lors d’un renvoi identique.

## Consentement et Meta

- [ ] La bannière consentement ne contient qu’un texte clair et « J’accepte ».
- [ ] Après le clic, la bannière disparaît avec une transition courte sans attendre la réponse réseau ; en cas d’échec simulé, elle réapparaît avec un message français.
- [ ] Sans consentement enregistré, aucun événement marketing ne doit être émis.
- [ ] Dans « Suivi Meta », sauvegarder Pixel seul, CAPI seul et les deux ; chaque cas affiche une notice compréhensible.
- [ ] Les diagnostics n’exposent ni token, ni téléphone, ni adresse, ni payload complet.
- [ ] Les tests Meta live restent sur le dataset de test ; ne pas modifier catalogue, Converty ou campagnes de production.

## Back-office

- [ ] Admin : accès produits, catégories, commandes, inventaire, réclamations, dashboard et diagnostics opérationnels.
- [ ] Admin : refus d’accès aux utilisateurs, promotions, livraison, champs commande, contenu, pages et réglages Meta réservés au Super Admin.
- [ ] Super Admin : gestion utilisateurs, mot de passe et protection contre la modification d’un autre Super Admin.
- [ ] Dashboard : KPI, commandes par statut, meilleures ventes/état vide, stock faible, réclamations et bloc Meta sont distincts et lisibles.
- [ ] Les chargements admin affichent un squelette léger sans masquer l’en-tête ni ajouter une requête.
- [ ] Gestion contenu : explication de placement visible, formulaire sans chevauchement, actions de création qui font défiler jusqu’au formulaire, et aperçu public.
- [ ] Sections produits : recherche + filtre catégorie, ajout/retrait, ordre, listes scrollables et enregistrement visible.
- [ ] Héros : image, statut, ordre haut/bas, édition et note d’aide fonctionnent.
- [ ] Pages statiques : édition, contenu assaini, redirection de slug, canonical et métadonnées.

## Opérations, stock et réclamations

- [ ] Modifier une commande autorisée : recalcul livraison cohérent, stock et historique corrects.
- [ ] Transitions livraison/retour/annulation : raison requise quand applicable et restauration de stock une seule fois.
- [ ] Réclamations : création publique avec consentement, honeypot, limites image ; aucune fuite sur une référence commande inconnue.
- [ ] Réclamations : recherche, filtres, note interne, transition de statut et fichier privé accessible uniquement au back-office autorisé.
- [ ] Journal d’audit : création, modification sensible, action Meta et action contenu visibles sans valeur secrète ou personnelle.

## Qualité, sécurité et performance

- [ ] `/up`, `/api/health/live` et `/api/health/ready` répondent correctement.
- [ ] Page inexistante : page française sûre et non indexable.
- [ ] Vérifier `robots.txt`, sitemap, canonicals, Open Graph et JSON-LD produit.
- [ ] À 200 % de zoom, tous les contrôles restent utilisables ; navigation clavier et focus visible sur les parcours critiques.
- [ ] Vérifier absence de débordement horizontal aux cinq largeurs ci-dessus.
- [ ] Navigation chaude accueil → catalogue → produit → panier et dashboard → produits → commandes : feedback visuel immédiat, pas de voile global bloquant.
- [ ] Après modification de contenu, catégorie, produit ou livraison, vérifier que la page publique reflète le changement après invalidation ciblée du cache.
- [ ] Vérifier que les erreurs Sentry et logs ne contiennent pas de corps checkout/réclamation, tokens ou cookies marketing.

## Sign-off

- [ ] Tous les contrôles applicables ci-dessus sont validés par le propriétaire.
- [ ] Les éléments différés (domaine HTTPS, sauvegarde/restauration, dataset Meta test, DNS/HSTS/CSP enforce) sont validés sur staging avant production.
- [ ] Les résultats, date, navigateur et environnement sont consignés dans le rapport de release.
