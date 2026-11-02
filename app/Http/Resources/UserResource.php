<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            // Email is personal data: only show it to the user themselves.
            'email' => $this->when($request->user()?->is($this->resource), $this->email),
            'posts_count' => $this->whenCounted('posts'),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
