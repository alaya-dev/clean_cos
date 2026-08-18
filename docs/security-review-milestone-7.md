# Revue sécurité — Milestone 7

Périmètre : comportements Laravel atteignables, contrôles applicatifs, dépendances et exposition des vues. Cette revue ne remplace pas un DAST/TLS scan de staging.

## Contrôles vérifiés dans le code
- Autorisations par politiques/gates sur APIs admin; les réglages globaux, contenu, utilisateurs, audit et Meta restent Super Admin.
- Prix, livraison, stock et commande sont autoritatifs côté serveur; les actions critiques utilisent transactions/verrous/idempotence existants.
- Fichiers de réclamation privés, uploads re-encodés/validés, URLs externes du contenu validées.
- Consentement isole Pixel/CAPI; token Meta chiffré et diagnostics minimisés.
- Sentry est configuré sans PII par défaut; les payloads Meta complets et les secrets ne sont pas des diagnostics.
- En-têtes ajoutés : nosniff, anti-frame, referrer policy, permissions policy, COOP, CSP progressive, HSTS activable seulement sous TLS, no-store pages privées.

## Vérifications de release restantes
- Exécuter DAST/header/TLS scan sur staging et inspecter CSP report-only avant `SECURITY_CSP_MODE=enforce`.
- Vérifier `APP_DEBUG=false`, cookie Secure HTTPS, durées de session et révocation avec la configuration staging.
- Refaire scans de secrets et dépendances; enregistrer toute exception dans `dependency-risk-register.md`.
- Ne pas conclure à une validation live Meta sans les identifiants propriétaires et les Test Events.
