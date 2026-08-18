<?php

namespace App\Http\Resources\Api\Admin;

use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray($request): array
    {
        return $this->resource->only(['public_id', 'name', 'email', 'role', 'is_active', 'force_password_change', 'created_at']);
    }
}
