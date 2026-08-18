<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Audit\Actions\RecordAuditEventAction;
use App\Domain\MetaTracking\Models\MetaConfiguration;
use App\Domain\MetaTracking\Models\MetaEvent;
use App\Domain\MetaTracking\Services\MetaConfigurationService;
use App\Domain\MetaTracking\Services\MetaConversionsClient;
use App\Domain\MetaTracking\Services\MetaDeliveryResult;
use App\Domain\Operations\Services\OperationalHealth;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Admin\SaveMetaConfigurationRequest;
use App\Http\Responses\ApiResponse;
use Carbon\Carbon;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MetaConfigurationController extends Controller
{
    public function __construct(private readonly MetaConfigurationService $configurations) {}

    public function show(OperationalHealth $health): JsonResponse
    {
        $activeConfiguration = MetaConfiguration::query()->where('state', 'active')->latest('activated_at')->first();
        $proposalQuery = MetaConfiguration::query()->where('state', 'proposed');
        if ($activeConfiguration !== null) {
            $proposalQuery->where('configuration_version', '>', $activeConfiguration->configuration_version);
        }
        $proposedConfiguration = $proposalQuery->latest('created_at')->first();
        $operational = $health->snapshot();
        $browserLastAttemptedAt = MetaEvent::query()->where('browser_state', 'attempted')->latest('updated_at')->value('updated_at');
        $serverLastAcceptedAt = MetaEvent::query()->where('capi_state', 'succeeded')->latest('capi_delivered_at')->value('capi_delivered_at');
        $pairedEventExists = MetaEvent::query()->where('browser_state', 'attempted')->where('capi_state', 'succeeded')->exists();

        return ApiResponse::success([
            'active' => $this->safe($activeConfiguration),
            'proposed' => $this->safe($proposedConfiguration),
            'graph_api_version' => config('meta.graph_api_version'),
            'delivery_diagnostics' => [
                'queue_worker_state' => $operational['queue_worker']['state'],
                'browser_pixel_last_attempted_at' => $browserLastAttemptedAt?->toIso8601String(),
                'server_capi_last_accepted_at' => $serverLastAcceptedAt?->toIso8601String(),
                'deduplication_status' => $pairedEventExists ? 'pending_confirmation' : 'not_observed',
            ],
        ]);
    }

    public function store(SaveMetaConfigurationRequest $request, RecordAuditEventAction $audit): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor !== null, 401);
        $data = $request->validated();
        $mode = (string) $data['mode'];
        $baseConfiguration = null;
        if (isset($data['base_configuration_public_id'])) {
            $baseConfiguration = MetaConfiguration::query()
                ->where('public_id', $data['base_configuration_public_id'])
                ->whereIn('state', ['proposed', 'active'])
                ->first();
        }
        $activeConfiguration = MetaConfiguration::query()->where('state', 'active')->latest('activated_at')->first();
        $token = trim((string) ($data['capi_access_token'] ?? ''));
        $existingPixelId = $baseConfiguration instanceof MetaConfiguration && filled($baseConfiguration->pixel_id)
            ? $baseConfiguration->pixel_id
            : $activeConfiguration?->pixel_id;
        $pixelId = trim((string) ($data['pixel_id'] ?? $existingPixelId ?? ''));
        $submittedDomainVerification = trim((string) ($data['facebook_domain_verification'] ?? ''));
        $existingDomainVerification = $baseConfiguration instanceof MetaConfiguration && filled($baseConfiguration->facebook_domain_verification)
            ? $baseConfiguration->facebook_domain_verification
            : $activeConfiguration?->facebook_domain_verification;
        $domainVerification = $submittedDomainVerification !== '' ? $submittedDomainVerification : $existingDomainVerification;
        $encryptedToken = $token !== ''
            ? Crypt::encryptString($token)
            : $this->preservedToken($baseConfiguration, $activeConfiguration);
        $submittedTestCode = trim((string) ($data['test_event_code'] ?? ''));
        $existingTestCode = $baseConfiguration instanceof MetaConfiguration && filled($baseConfiguration->test_event_code)
            ? $baseConfiguration->test_event_code
            : $activeConfiguration?->test_event_code;
        $testCode = $submittedTestCode !== '' ? $submittedTestCode : (string) ($existingTestCode ?? '');

        if ($mode !== 'disabled') {
            $missing = array_values(array_filter([
                $pixelId === '' ? 'Identifiant Pixel' : null,
                blank($encryptedToken) ? 'jeton CAPI' : null,
                $mode === 'test' && $testCode === '' ? 'code d’événement de test' : null,
            ]));
            if ($missing !== []) {
                throw ValidationException::withMessages(['configuration' => 'Complétez les champs requis : '.implode(', ', $missing).'.']);
            }
        }

        $configuration = MetaConfiguration::query()->create([
            'configuration_version' => ((int) MetaConfiguration::query()->max('configuration_version')) + 1,
            'state' => 'proposed',
            'tracking_enabled' => $mode !== 'disabled',
            'pixel_id' => $pixelId !== '' ? $pixelId : null,
            'facebook_domain_verification' => $domainVerification,
            'capi_access_token_encrypted' => $encryptedToken,
            'test_mode' => $mode === 'test',
            'test_event_code' => $mode === 'test' ? $testCode : null,
            'created_by' => $actor->id,
        ]);
        $audit->handle('meta.configuration_proposed', $configuration, $actor, after: [
            'configuration_version' => $configuration->configuration_version,
            'mode' => $mode,
            'pixel_id_configured' => $configuration->pixel_id !== null,
            'domain_verification_configured' => $configuration->facebook_domain_verification !== null,
            'token_replaced' => $token !== '',
        ]);

        if ($mode === 'disabled') {
            $configuration->update(['tested_at' => now(), 'test_outcome' => 'succeeded']);
            $configuration = $this->activateConfiguration($configuration, $actor->id);
            $audit->handle('meta.configuration_activated', $configuration, $actor, after: ['configuration_version' => $configuration->configuration_version, 'mode' => 'disabled']);

            return ApiResponse::success(['active' => $this->safe($configuration), 'proposed' => null, 'notice' => 'Le suivi Meta est désactivé.'], status: 201);
        }

        return ApiResponse::success([
            'proposed' => $this->safe($configuration),
            'notice' => $mode === 'test'
                ? 'Configuration Test enregistrée. Lancez le test serveur pour l’activer.'
                : 'Configuration Production enregistrée. Testez la connexion, puis confirmez son activation.',
        ], status: 201);
    }

    public function test(MetaConfiguration $configuration, RecordAuditEventAction $audit, MetaConversionsClient $client): JsonResponse
    {
        abort_unless(in_array($configuration->state, ['proposed', 'active'], true), 404);
        $result = $client->testConnection($configuration);
        $testedAt = now();
        $configuration->update([
            'tested_at' => $testedAt,
            'test_outcome' => $result->accepted ? 'succeeded' : 'failed',
            'last_test_request_sent' => $result->requestSent,
            'last_test_http_status' => $result->httpStatus,
            'last_test_events_received' => $result->eventsReceived,
            'last_test_error_code' => $result->metaErrorCode,
            'last_test_error_subcode' => $result->metaErrorSubcode,
            'last_test_message' => $result->metaMessage,
            'last_test_fbtrace_id' => $result->fbtraceId,
            'last_test_classification' => $result->classification,
            'last_test_graph_api_version' => $result->graphApiVersion,
            'last_test_source_url' => $result->sourceUrl,
        ]);
        $this->recordSyntheticAttempt($configuration, $result, $testedAt);
        $actor = request()->user();
        $audit->handle('meta.configuration_tested', $configuration, $actor, after: [
            'configuration_version' => $configuration->configuration_version,
            'outcome' => $configuration->test_outcome,
            'http_status' => $result->httpStatus,
            'classification' => $result->classification,
        ]);

        if (! $result->accepted) {
            return ApiResponse::error(
                'META_CONNECTION_TEST_FAILED',
                $this->failureMessage($result),
                422,
                meta: ['test_result' => $result->diagnostics()],
            );
        }

        if ($configuration->state === 'proposed' && $configuration->test_mode && $actor !== null) {
            $configuration = $this->activateConfiguration($configuration, (int) $actor->getAuthIdentifier());
            $audit->handle('meta.configuration_activated', $configuration, $actor, after: ['configuration_version' => $configuration->configuration_version, 'mode' => 'test']);
        }

        return ApiResponse::success([
            'test_outcome' => 'succeeded',
            'active' => $configuration->state === 'active' ? $this->safe($configuration) : null,
            'test_result' => $result->diagnostics(),
            'notice' => $configuration->state === 'active'
                ? 'Connexion serveur validée. Le mode Test est actif.'
                : 'Connexion serveur validée. Vous pouvez activer la Production.',
        ]);
    }

    public function activate(Request $request, MetaConfiguration $configuration, RecordAuditEventAction $audit): JsonResponse
    {
        $data = $request->validate([
            'configuration_version' => ['required', 'integer', 'min:1'],
            'confirm_production' => ['required', 'accepted'],
        ]);
        $actor = $request->user();
        abort_unless($actor !== null, 401);
        if ($configuration->test_mode) {
            return ApiResponse::error('META_MODE_INVALID', 'Le mode Test est activé automatiquement après un test réussi.', 422);
        }
        if ($configuration->configuration_version !== $data['configuration_version']) {
            return ApiResponse::error('META_CONFIGURATION_CONFLICT', 'La configuration a changé. Actualisez la page.', 409);
        }
        $testedAt = $configuration->getRawOriginal('tested_at');
        if (! is_string($testedAt) || $configuration->test_outcome !== 'succeeded' || ! Carbon::parse($testedAt)->greaterThan(now()->subMinutes(15))) {
            throw ValidationException::withMessages(['configuration' => 'Un test serveur réussi de moins de 15 minutes est requis.']);
        }
        $active = $this->activateConfiguration($configuration, (int) $actor->getAuthIdentifier());
        $audit->handle('meta.configuration_activated', $active, $actor, after: ['configuration_version' => $active->configuration_version, 'mode' => 'live']);

        return ApiResponse::success(['active' => $this->safe($active), 'notice' => 'La configuration Production est active.']);
    }

    public function removeToken(Request $request, RecordAuditEventAction $audit): JsonResponse
    {
        $request->validate(['confirm_removal' => ['required', 'accepted']]);
        $actor = $request->user();
        abort_unless($actor !== null, 401);
        $active = MetaConfiguration::query()->where('state', 'active')->latest('activated_at')->first();
        $configuration = MetaConfiguration::query()->create([
            'configuration_version' => ((int) MetaConfiguration::query()->max('configuration_version')) + 1,
            'state' => 'proposed',
            'tracking_enabled' => false,
            'pixel_id' => $active?->pixel_id,
            'facebook_domain_verification' => $active?->facebook_domain_verification,
            'capi_access_token_encrypted' => null,
            'test_mode' => false,
            'test_event_code' => null,
            'tested_at' => now(),
            'test_outcome' => 'succeeded',
            'created_by' => $actor->id,
        ]);
        $configuration = $this->activateConfiguration($configuration, (int) $actor->getAuthIdentifier());
        $audit->handle('meta.configuration_token_removed', $configuration, $actor, after: [
            'configuration_version' => $configuration->configuration_version,
            'mode' => 'disabled',
            'token_configured' => false,
        ]);

        return ApiResponse::success(['active' => $this->safe($configuration), 'notice' => 'Le jeton CAPI a été supprimé et le suivi Meta désactivé.']);
    }

    private function activateConfiguration(MetaConfiguration $configuration, int $actorId): MetaConfiguration
    {
        $active = DB::transaction(function () use ($configuration, $actorId): MetaConfiguration {
            $locked = MetaConfiguration::query()->whereKey($configuration->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->state === 'proposed', 409);
            MetaConfiguration::query()->whereIn('state', ['active', 'proposed'])->where('id', '!=', $locked->id)->lockForUpdate()->update(['state' => 'superseded']);
            $locked->update(['state' => 'active', 'activated_at' => now(), 'activated_by' => $actorId]);

            return $locked->fresh() ?? $locked;
        });
        $this->configurations->forgetFacebookDomainVerification();

        return $active;
    }

    private function recordSyntheticAttempt(MetaConfiguration $configuration, MetaDeliveryResult $result, \DateTimeInterface $testedAt): void
    {
        $event = MetaEvent::query()->create([
            'event_name' => 'PageView',
            'meta_configuration_id' => $configuration->id,
            'event_time' => $testedAt,
            'consent_policy_version' => (int) config('meta.consent_policy_version'),
            'marketing_consent' => false,
            'is_synthetic' => true,
            'source_url' => $result->sourceUrl,
            'context_summary' => ['route_type' => 'synthetic_connection_test'],
            'payload_hash' => hash('sha256', 'synthetic:'.$configuration->public_id.':'.$testedAt->format('Uu')),
            'capi_state' => $result->accepted
                ? 'succeeded'
                : (in_array($result->classification, ['configuration_invalid', 'token_decryption_failed'], true)
                    ? 'skipped_no_active_configuration'
                    : ($result->temporary ? 'temporary_failure' : 'permanent_failure')),
            'last_error_classification' => $result->accepted ? null : $result->classification,
            'capi_delivered_at' => $result->accepted ? $testedAt : null,
        ]);
        $event->attempts()->create([
            'channel' => 'synthetic_test',
            'attempt_number' => 1,
            'outcome' => $result->accepted ? 'succeeded' : 'failed',
            'request_sent' => $result->requestSent,
            'http_status' => $result->httpStatus,
            'events_received' => $result->eventsReceived,
            'error_classification' => $result->accepted ? null : $result->classification,
            'meta_error_code' => $result->metaErrorCode,
            'meta_error_subcode' => $result->metaErrorSubcode,
            'safe_message' => $result->metaMessage,
            'fbtrace_id' => $result->fbtraceId,
            'graph_api_version' => $result->graphApiVersion,
            'attempted_at' => $testedAt,
        ]);
    }

    /** @return array<string, mixed>|null */
    private function safe(?MetaConfiguration $configuration): ?array
    {
        if ($configuration === null) {
            return null;
        }

        return [
            'public_id' => $configuration->public_id,
            'configuration_version' => $configuration->configuration_version,
            'state' => $configuration->state,
            'mode' => ! $configuration->tracking_enabled ? 'disabled' : ($configuration->test_mode ? 'test' : 'live'),
            'tracking_enabled' => $configuration->tracking_enabled,
            'pixel_id' => $configuration->pixel_id,
            'domain_verification_configured' => filled($configuration->facebook_domain_verification),
            'token_configured' => filled($configuration->capi_access_token_encrypted),
            'test_mode' => $configuration->test_mode,
            'test_event_code_configured' => filled($configuration->test_event_code),
            'tested_at' => optional($configuration->tested_at)?->toIso8601String(),
            'test_outcome' => $configuration->test_outcome,
            'activated_at' => optional($configuration->activated_at)?->toIso8601String(),
            'last_test' => [
                'request_sent' => $configuration->last_test_request_sent,
                'http_status' => $configuration->last_test_http_status,
                'events_received' => $configuration->last_test_events_received,
                'error_code' => $configuration->last_test_error_code,
                'error_subcode' => $configuration->last_test_error_subcode,
                'message' => $configuration->last_test_message,
                'fbtrace_id' => $configuration->last_test_fbtrace_id,
                'classification' => $configuration->last_test_classification,
                'graph_api_version' => $configuration->last_test_graph_api_version,
                'source_url' => $configuration->last_test_source_url,
            ],
        ];
    }

    private function failureMessage(MetaDeliveryResult $result): string
    {
        return match ($result->classification) {
            'configuration_invalid', 'token_decryption_failed' => 'Le test n’a pas été envoyé à Meta, car la configuration CAPI est incomplète ou le jeton enregistré est indisponible.',
            'meta_rate_limited' => 'Meta limite temporairement les tests. Réessayez dans une minute.',
            'timeout', 'network_error' => 'Meta est momentanément injoignable. Réessayez dans quelques instants.',
            'request_not_sent' => 'La requête n’a pas pu être préparée ou envoyée. Vérifiez la configuration puis réessayez.',
            'meta_rejected' => $result->metaMessage ?: 'Meta a reçu la requête mais l’a refusée. Vérifiez le détail du test.',
            default => 'Le test serveur n’a pas pu être terminé.',
        };
    }

    private function preservedToken(?MetaConfiguration $base, ?MetaConfiguration $active): ?string
    {
        foreach ([$base, $active] as $configuration) {
            $encrypted = $configuration?->capi_access_token_encrypted;
            if (! is_string($encrypted) || $encrypted === '') {
                continue;
            }
            try {
                Crypt::decryptString($encrypted);

                return $encrypted;
            } catch (DecryptException) {
                continue;
            }
        }

        return null;
    }
}
