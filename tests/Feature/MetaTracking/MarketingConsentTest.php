<?php

namespace Tests\Feature\MetaTracking;

use App\Domain\MetaTracking\Models\MarketingConsent;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class MarketingConsentTest extends TestCase
{
    public function test_marketing_consent_is_disabled_by_default_and_a_tampered_cookie_is_ignored(): void
    {
        $this->getJson('/api/v1/public/marketing-consent')
            ->assertOk()
            ->assertJsonPath('data.necessary', true)
            ->assertJsonPath('data.marketing', false)
            ->assertJsonPath('data.decided', false)
            ->assertJsonPath('data.policy_version', 1);

        $this->withCookie('pc_marketing_consent', 'tampered')
            ->getJson('/api/v1/public/marketing-consent')
            ->assertOk()
            ->assertJsonPath('data.marketing', false);
    }

    public function test_accept_and_refuse_create_minimal_server_receipts(): void
    {
        $this->postJson('/api/v1/public/marketing-consent', ['decision' => 'accept_all'])
            ->assertOk()
            ->assertJsonPath('data.marketing', true)
            ->assertJsonPath('data.decided', true);

        $this->assertDatabaseHas('marketing_consents', [
            'policy_version' => 1,
            'necessary_consent' => true,
            'marketing_consent' => true,
        ]);

        $this->postJson('/api/v1/public/marketing-consent', ['decision' => 'refuse_optional'])
            ->assertOk()
            ->assertJsonPath('data.marketing', false);

        $this->assertDatabaseHas('marketing_consents', ['marketing_consent' => false]);
    }

    public function test_withdrawal_clears_the_cookie_and_current_state_is_marketing_denied(): void
    {
        $this->postJson('/api/v1/public/marketing-consent', ['decision' => 'withdraw'])
            ->assertOk()
            ->assertJsonPath('data.marketing', false)
            ->assertJsonPath('data.decided', false)
            ->assertCookieExpired('pc_marketing_consent');
    }

    public function test_an_outdated_policy_version_is_not_valid_marketing_consent(): void
    {
        $receipt = MarketingConsent::query()->create([
            'policy_version' => 0,
            'necessary_consent' => true,
            'marketing_consent' => true,
            'decided_at' => now(),
        ]);
        $cookie = Crypt::encryptString(json_encode(['receipt' => $receipt->public_id, 'policy_version' => 0], JSON_THROW_ON_ERROR));

        $this->withCookie('pc_marketing_consent', $cookie)
            ->getJson('/api/v1/public/marketing-consent')
            ->assertOk()
            ->assertJsonPath('data.marketing', false);
    }
}
