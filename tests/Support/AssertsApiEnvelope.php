<?php

namespace Tests\Support;

use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Assert;

trait AssertsApiEnvelope
{
    public function assertErrorEnvelope(TestResponse $response, int $status, string $code): void
    {
        $response->assertStatus($status)->assertJsonPath('code', $code)->assertJsonStructure(['code', 'message']);
        Assert::assertIsString((string) $response->json('message'));
    }

    public function assertDoesNotLeakSecrets(array $payload): void
    {
        $flattened = json_encode($payload, JSON_THROW_ON_ERROR);
        foreach (['password', 'token', 'session', 'csrf', 'refresh_token', 'access_token', 'raw request body'] as $needle) {
            Assert::assertFalse(str_contains(strtolower($flattened), strtolower($needle)), "Payload leaked sensitive term {$needle}");
        }
    }
}
