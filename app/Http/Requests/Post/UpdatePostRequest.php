<?php

namespace App\Http\Requests\Post;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePostRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Only the author may update. Returns 403 otherwise.
        return $this->user()->can('update', $this->route('post'));
    }

    public function rules(): array
    {
        // PATCH is a partial update, so every field is optional but must be valid if sent.
        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'body' => ['sometimes', 'required', 'string', 'max:20000'],
        ];
    }
}
