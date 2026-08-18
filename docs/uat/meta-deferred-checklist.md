# UAT — Meta différé (identifiants propriétaires requis)

État : **non exécuté**. N’utiliser que le dataset/Test Event Code du propriétaire, jamais le catalogue ou les campagnes de production.

- [ ] Pixel chargé après consentement et absent avant/refus/retrait.
- [ ] CAPI fake/staging : même `event_id`, `content_ids`, quantité, prix, TND et valeur que le navigateur.
- [ ] Test Events : ViewContent, AddToCart, InitiateCheckout, Purchase unique après commit.
- [ ] retry timeout/429/5xx garde le même event ID; 4xx permanent est visible sans PII.
- [ ] aucune modification de Converty, dataset, catalogue ou ads actifs.

Propriétaire : ____ Date : ____ Dataset de test : ____ Preuve : ____
