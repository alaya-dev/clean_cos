<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Audit\Actions\RecordAuditEventAction;
use App\Domain\FirstDelivery\Models\FirstDeliveryConfiguration;
use App\Domain\FirstDelivery\Services\FirstDeliveryClient;
use App\Domain\FirstDelivery\Services\FirstDeliveryConfigurationService;
use App\Domain\FirstDelivery\Services\FirstDeliveryLocalityService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Admin\SaveFirstDeliveryConfigurationRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FirstDeliveryConfigurationController extends Controller
{
    public function __construct(private readonly FirstDeliveryConfigurationService $configurations) {}

    public function show(): JsonResponse
    {
        $configuration = $this->configurations->current();

        return response()->json([
            'data' => [
                'configuration' => $configuration ? $this->configurations->safe($configuration) : null,
            ],
        ]);
    }

    public function store(
        SaveFirstDeliveryConfigurationRequest $request,
        RecordAuditEventAction $audit,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();
        $data = $request->validated();

        $configuration = DB::transaction(function () use ($actor, $data, $audit): FirstDeliveryConfiguration {
            $existing = FirstDeliveryConfiguration::query()->lockForUpdate()->latest('updated_at')->first();
            $configuration = $existing ?? new FirstDeliveryConfiguration;
            $before = $existing ? $this->configurations->safe($existing) : [];
            $configuration->fill([
                'mode' => $data['mode'],
                'api_base_url' => rtrim($data['api_base_url'], '/'),
                'token_encrypted' => $this->configurations->encryptWhenProvided(
                    $data['first_delivery_token'] ?? null,
                    $existing?->token_encrypted,
                ),
                'updated_by' => $actor->id,
            ]);
            $configuration->save();
            $audit->handle(
                'delivery.first_delivery.configuration_saved',
                $configuration,
                $actor,
                $before,
                $this->configurations->safe($configuration),
            );

            return $configuration->fresh() ?? $configuration;
        });

        return response()->json([
            'data' => [
                'configuration' => $this->configurations->safe($configuration),
                'notice' => 'Configuration First Delivery enregistrée.',
            ],
        ]);
    }

    public function test(
        FirstDeliveryConfiguration $configuration,
        Request $request,
        FirstDeliveryClient $client,
        FirstDeliveryLocalityService $localities,
        RecordAuditEventAction $audit,
    ): JsonResponse {
        $result = $client->getLocalities($configuration);
        $providerLocalities = data_get($result->payload, 'result');
        $count = $result->accepted && is_array($providerLocalities)
            ? $localities->synchronize($providerLocalities)
            : 0;
        $status = $result->accepted && $count > 0 ? 'connected' : $result->classification;
        $message = $status === 'connected'
            ? $count.' localité'.($count > 1 ? 's' : '').' First Delivery synchronisée'.($count > 1 ? 's' : '').'.'
            : $result->safeMessage;

        $configuration->update([
            'last_tested_at' => now(),
            'last_test_status' => $status,
            'last_test_message' => $message,
            'last_localities_synced_at' => $count > 0 ? now() : $configuration->last_localities_synced_at,
        ]);
        $audit->handle(
            'delivery.first_delivery.connection_tested',
            $configuration,
            $request->user(),
            after: [
                'status' => $status,
                'request_sent' => $result->requestSent,
                'http_status' => $result->httpStatus,
                'localities_count' => $count,
            ],
        );

        return response()->json([
            'data' => [
                'configuration' => $this->configurations->safe($configuration->fresh() ?? $configuration),
                'test_result' => [
                    'request_sent' => $result->requestSent,
                    'http_status' => $result->httpStatus,
                    'status' => $status,
                    'message' => $message,
                    'localities_count' => $count,
                ],
            ],
        ]);
    }

    public function removeToken(Request $request, RecordAuditEventAction $audit): JsonResponse
    {
        $request->validate(['confirm_removal' => ['required', 'accepted']]);
        $configuration = FirstDeliveryConfiguration::query()->latest('updated_at')->firstOrFail();
        $configuration->update(['token_encrypted' => null, 'mode' => 'disabled']);
        $audit->handle(
            'delivery.first_delivery.token_removed',
            $configuration,
            $request->user(),
            after: ['mode' => 'disabled', 'token_configured' => false],
        );

        return response()->json([
            'data' => [
                'configuration' => $this->configurations->safe($configuration->fresh() ?? $configuration),
                'notice' => 'Le token First Delivery a été supprimé et l’intégration désactivée.',
            ],
        ]);
    }
}
