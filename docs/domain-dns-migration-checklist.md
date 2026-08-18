# Domaine, DNS et migration — checklist propriétaire

- [ ] `APP_URL` HTTPS définitif renseigné en staging puis production.
- [ ] certificat TLS, redirection HTTPS, HSTS et en-têtes validés.
- [ ] DNS apex/www, cache CDN/proxy éventuel et e-mails transactionnels vérifiés.
- [ ] sitemap/canonical/robots référencent le domaine final.
- [ ] CSP passée de report-only à enforce seulement après observation des hôtes requis.
- [ ] rollback DNS/documenté; Converty et tags existants inchangés sans plan de bascule signé.
