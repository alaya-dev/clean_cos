# Investiguer une file en échec

Vérifier Redis et le worker : `php artisan queue:failed`, journaux sans PII, connectivité DB/stockage. Corriger la cause puis relancer seulement le job ciblé. Ne jamais relancer aveuglément les commandes ou Purchase Meta; vérifier idempotence et `event_id`.
