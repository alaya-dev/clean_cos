# UAT — Back office

| Scénario | Pré-requis / étapes | Attendu | Réel | Pass/Fail | Preuve / défaut |
|---|---|---|---|---|---|
| Connexion | admin, super admin, compte désactivé, rate-limit | accès/deni corrects, session sûre | | | |
| Catalogue | créer/éditer produit, image, stock, ID catalogue Meta | validations, audit, aucune fuite | | | |
| Commandes | modifier nouvelle, confirmer/livrer/retourner | transitions et stock cohérents | | | |
| Gestion magasin | promo, livraison, champs, contenu, pages | Super Admin seul, cache invalidé | | | |
| Support | réclamation, note, statut, pièce jointe | Admin/Super Admin, fichier privé | | | |
| Visibilité | dashboard, audit, diagnostics Meta | rôle et données minimisées | | | |
