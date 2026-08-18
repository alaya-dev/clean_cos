<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Audit\Actions\RecordAuditEventAction;
use App\Domain\IdentityAccess\Actions\ChangePasswordAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Admin\ChangePasswordRequest;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class PasswordController extends Controller
{
    public function update(ChangePasswordRequest $request, RecordAuditEventAction $audit, ChangePasswordAction $change): JsonResponse
    {
        $user = $request->user();
        abort_unless($user !== null, 401);
        try {
            $change->handle($user, $request->string('current_password'), $request->string('password'));
        } catch (ValidationException) {
            return ApiResponse::error('INVALID_CURRENT_PASSWORD', 'Le mot de passe actuel est incorrect.', 422);
        }
        $audit->handle('user.password_changed', $user, $user);

        return ApiResponse::success(['password_changed' => true]);
    }
}
