<?php

namespace App\Domain\Audit\Actions;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Commerce\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class RecordAuditEventAction
{
    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function handle(string $action, Model $auditable, ?User $actor = null, array $before = [], array $after = [], ?string $requestId = null): ?AuditLog
    {
        if (str_starts_with($action, 'meta.') || str_starts_with($action, 'navex.')) {
            return null;
        }

        return AuditLog::query()->create([
            'actor_user_id' => $actor?->id,
            'actor_role_snapshot' => $actor?->role,
            'action' => $action,
            'auditable_type' => $this->auditableType($auditable),
            'auditable_id' => $this->auditableId($auditable),
            'request_id' => $requestId,
            'before' => $this->sanitize($before),
            'after' => $this->sanitize($after),
        ]);
    }

    /** @return array<string, mixed> */
    /** @param array<string, mixed> $values */
    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function sanitize(array $values): array
    {
        $hidden = ['password', 'password_confirmation', 'password_hash', 'current_password', 'remember_token', 'session', 'session_id', 'csrf', 'csrf_token', 'token', 'access_token', 'refresh_token', 'capi_access_token', 'capi_access_token_encrypted', 'test_event_code', 'name', 'full_name', 'customer_name', 'email', 'phone', 'telephone', 'address', 'customer_address', 'subject', 'description', 'body', 'note', 'notes', 'attachment', 'raw_attribution', 'request_body', '_fbp', '_fbc', 'phone_hash'];

        $sanitized = [];
        foreach ($values as $key => $value) {
            $normalizedKey = strtolower((string) $key);
            if (in_array($normalizedKey, $hidden, true) || str_contains($normalizedKey, 'credential')) {
                continue;
            }

            $sanitized[$key] = is_array($value) ? $this->sanitize($value) : $value;
        }

        return $sanitized;
    }

    private function auditableType(Model $auditable): string
    {
        return $auditable->getMorphClass();
    }

    private function auditableId(Model $auditable): string
    {
        return $auditable instanceof Order && filled($auditable->public_reference)
            ? (string) $auditable->public_reference
            : (string) $auditable->getKey();
    }
}
