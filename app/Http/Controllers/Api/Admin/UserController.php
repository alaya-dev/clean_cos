<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Audit\Actions\RecordAuditEventAction;
use App\Domain\IdentityAccess\Actions\ManageBackOfficeUserAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Admin\StoreBackOfficeUserRequest;
use App\Http\Requests\Api\Admin\UpdateBackOfficeUserRequest;
use App\Http\Resources\Api\Admin\UserResource;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', 'in:admin,super_admin'],
            'is_active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);
        $users = User::query()
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->where(fn ($nested) => $nested->where('name', 'like', '%'.$search.'%')->orWhere('email', 'like', '%'.$search.'%')))
            ->when($filters['role'] ?? null, fn ($query, $role) => $query->where('role', $role))
            ->when(array_key_exists('is_active', $filters), fn ($query) => $query->where('is_active', $filters['is_active']))
            ->orderBy('name')
            ->paginate($filters['per_page'] ?? 25);

        return ApiResponse::success([
            'data' => UserResource::collection($users->getCollection())->resolve(),
            'meta' => ['current_page' => $users->currentPage(), 'last_page' => $users->lastPage(), 'total' => $users->total()],
        ]);
    }

    public function show(User $user): JsonResponse
    {
        return ApiResponse::success(new UserResource($user));
    }

    public function store(StoreBackOfficeUserRequest $request, RecordAuditEventAction $audit, ManageBackOfficeUserAction $manage): JsonResponse
    {
        $data = $request->validated();
        $user = $manage->create($data);
        $audit->handle('user.created', $user, $request->user(), after: ['role' => $user->role, 'is_active' => $user->is_active]);

        return ApiResponse::success(new UserResource($user), status: 201);
    }

    public function update(UpdateBackOfficeUserRequest $request, User $user, RecordAuditEventAction $audit, ManageBackOfficeUserAction $manage): JsonResponse
    {
        $data = $request->validated();
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $manage->assertCanChange($user, $actor, $data);
        $before = $user->only(['name', 'email', 'role', 'is_active']);
        $updatedUser = $manage->update($user, $data);
        $audit->handle('user.updated', $updatedUser, $actor, before: $before, after: $updatedUser->only(['name', 'email', 'role', 'is_active']));

        return ApiResponse::success(new UserResource($updatedUser));
    }

    public function destroy(Request $request, User $user, RecordAuditEventAction $audit, ManageBackOfficeUserAction $manage): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $before = $user->only(['public_id', 'role', 'is_active']);

        DB::transaction(function () use ($user, $actor, $audit, $manage, $before): void {
            $manage->delete($user, $actor);
            $audit->handle('user.deleted', $user, $actor, before: $before);
        });

        return response()->json(['data' => null]);
    }
}
