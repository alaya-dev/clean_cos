<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Audit\Actions\RecordAuditEventAction;
use App\Domain\Navex\Models\NavexConfiguration;
use App\Domain\Navex\Services\NavexClient;
use App\Domain\Navex\Services\NavexConfigurationService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Admin\SaveNavexConfigurationRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class NavexConfigurationController extends Controller
{
    public function __construct(private readonly NavexConfigurationService $configurations) {}

    public function show(): JsonResponse
    {
        $configuration = $this->configurations->current();

        return response()->json(['data' => ['configuration' => $configuration ? $this->configurations->safe($configuration) : null]]);
    }

    public function store(SaveNavexConfigurationRequest $request, RecordAuditEventAction $audit): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $data = $request->validated();
        $configuration = DB::transaction(function () use ($actor, $data, $audit): NavexConfiguration {
            $existing = NavexConfiguration::query()->lockForUpdate()->latest('updated_at')->first();
            $configuration = $existing ?? new NavexConfiguration;
            $before = $existing ? $this->configurations->safe($existing) : [];
            $configuration->fill([
                'mode' => $data['mode'],
                'api_base_url' => rtrim($data['api_base_url'], '/'),
                'sender_name' => filled($data['sender_name'] ?? null) ? trim($data['sender_name']) : null,
                'sender_location' => filled($data['sender_location'] ?? null) ? trim($data['sender_location']) : null,
                'sender_governorate' => filled($data['sender_governorate'] ?? null) ? trim($data['sender_governorate']) : null,
                // Opening the package is a fixed operational rule, not an admin setting.
                'parcel_opening_option' => 'Oui',
                'creation_credential_encrypted' => $this->configurations->encryptWhenProvided($data['creation_credential'] ?? null, $existing?->creation_credential_encrypted),
                'tracking_credential_encrypted' => $this->configurations->encryptWhenProvided($data['tracking_credential'] ?? null, $existing?->tracking_credential_encrypted),
                'deletion_credential_encrypted' => $this->configurations->encryptWhenProvided($data['deletion_credential'] ?? null, $existing?->deletion_credential_encrypted),
                'updated_by' => $actor->id,
            ]);
            $configuration->save();
            $audit->handle('navex.configuration_saved', $configuration, $actor, $before, $this->configurations->safe($configuration));

            return $configuration->fresh() ?? $configuration;
        });

        return response()->json(['data' => ['configuration' => $this->configurations->safe($configuration), 'notice' => 'Configuration Navex enregistrée.']]);
    }

    public function test(NavexConfiguration $configuration, Request $request, NavexClient $client, RecordAuditEventAction $audit): JsonResponse
    {
        $result = $client->track($configuration, 'PC-NAVEX-CONNECTION-TEST-DO-NOT-EXIST');
        $status = $result->accepted ? 'connected' : $result->classification;
        $configuration->update(['last_tested_at' => now(), 'last_test_status' => $status, 'last_test_message' => $result->safeMessage]);
        $audit->handle('navex.connection_tested', $configuration, $request->user(), after: ['status' => $status, 'request_sent' => $result->requestSent, 'http_status' => $result->httpStatus]);

        return response()->json(['data' => [
            'configuration' => $this->configurations->safe($configuration->fresh() ?? $configuration),
            'test_result' => ['request_sent' => $result->requestSent, 'http_status' => $result->httpStatus, 'status' => $status, 'message' => $result->safeMessage],
        ]]);
    }

    public function removeCredential(Request $request, string $credential, RecordAuditEventAction $audit): JsonResponse
    {
        $data = $request->validate(['confirm_removal' => ['required', 'accepted']]);
        $field = match ($credential) {
            'creation' => 'creation_credential_encrypted',
            'tracking' => 'tracking_credential_encrypted',
            'deletion' => 'deletion_credential_encrypted',
            default => throw ValidationException::withMessages(['credential' => 'Identifiant Navex invalide.']),
        };
        $configuration = NavexConfiguration::query()->latest('updated_at')->first();
        if ($configuration === null) {
            abort(404);
        }
        $configuration->update([$field => null]);
        $audit->handle('navex.credential_removed', $configuration, $request->user(), after: ['credential' => $credential]);

        return response()->json(['data' => ['configuration' => $this->configurations->safe($configuration->fresh() ?? $configuration)]]);
    }
}
