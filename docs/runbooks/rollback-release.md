# Retour arrière de release

Stopper le trafic si nécessaire, relever release/migrations, revenir au build précédent compatible et ne jamais exécuter de migration destructive inverse sans sauvegarde validée. Vider uniquement les caches applicatifs ciblés, relancer workers, smoke-test et consigner.
