<?php

namespace App\Http\Requests\Api\V1\Public;

use Illuminate\Foundation\Http\FormRequest;

class CaptureSearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'q' => 'required|string|min:1|max:200',
            'limit' => 'integer|min:1|max:200',
            'use_cache' => 'boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'q.required' => 'Search query is required.',
            'q.min' => 'Search query must be at least 1 character.',
            'limit.max' => 'Limit cannot exceed 200.',
        ];
    }
}