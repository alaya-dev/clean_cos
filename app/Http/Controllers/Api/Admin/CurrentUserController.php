<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Audit\Actions\RecordAuditEventAction;
use App\Domain\Commerce\Models\Order;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class CurrentUserController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        return ApiResponse::success([
            ...$user->only(['public_id', 'name', 'email', 'role', 'is_active', 'force_password_change']),
            'order_polling' => config('admin.order_polling'),
            'new_orders_count' => Order::query()
                ->whereNull('archived_at')
                ->where('status', 'nouvelle')
                ->count(),
        ]);
    }

    public function password(Request $request, RecordAuditEventAction $audit): JsonResponse
    {
        $data = $request->validate(['current_password' => ['required', 'string'], 'password' => ['required', 'string', 'min:8', 'confirmed']], [
            'password.min' => 'Le nouveau mot de passe doit contenir au moins 8 caractères.',
            'password.confirmed' => 'La confirmation du nouveau mot de passe ne correspond pas.',
        ]);
        $user = $request->user();
        abort_unless($user !== null, 401);
        if (! Hash::check($data['current_password'], $user->password)) {
            return ApiResponse::error('INVALID_CURRENT_PASSWORD', 'Le mot de passe actuel est incorrect.', 422);
        }
        $user->force_password_change = false;
        $user->password = $data['password'];
        $user->auth_version++;
        $user->save();
        $audit->handle('user.password_changed', $user, $user);

        return ApiResponse::success(['password_changed' => true]);
    }
}
