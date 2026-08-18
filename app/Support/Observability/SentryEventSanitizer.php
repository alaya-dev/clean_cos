<?php

namespace App\Support\Observability;

use Sentry\Event;

final class SentryEventSanitizer
{
    public static function sanitize(Event $event): Event
    {
        $event->setRequest([]);
        $event->setUser(null);
        $event->setExtra([]);

        return $event;
    }
}
