<?php

namespace Tests\Unit;

use App\Support\Observability\SentryEventSanitizer;
use PHPUnit\Framework\TestCase;
use Sentry\Event;
use Sentry\UserDataBag;

class SentryEventSanitizerTest extends TestCase
{
    public function test_it_removes_request_user_and_extra_data(): void
    {
        $event = Event::createEvent()
            ->setRequest(['headers' => ['authorization' => 'secret'], 'data' => ['customer' => 'Ada']])
            ->setUser(new UserDataBag('user-1', 'ada@example.test'))
            ->setExtra(['phone' => '22123456']);

        $sanitized = SentryEventSanitizer::sanitize($event);

        self::assertSame([], $sanitized->getRequest());
        self::assertNull($sanitized->getUser());
        self::assertSame([], $sanitized->getExtra());
    }
}
