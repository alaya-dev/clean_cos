# Rapport accessibilité — Milestone 7

## Validation automatisée

Playwright couvre navigation clavier, drawer/panier, restauration du focus, consentement, mouvement réduit et débordement de 320 à 1440 px. Axe (WCAG 2 A/AA) est exécuté sur l’accueil, catalogue, panier, commande et réclamation; aucune violation critique ou sérieuse ne subsiste dans ce périmètre.

Corrections : contraste renforcé des accents/footer et libellé accessible ajouté aux liens d’image produit sans visuel.

## Vérification manuelle staging requise

Lecteurs d’écran, zoom navigateur 200 %, Safari/iOS, Firefox, Edge, contrastes de contenus fournis par le propriétaire, et tous écrans admin doivent être validés par le testeur UAT dans `docs/uat/mobile-checklist.md`.
