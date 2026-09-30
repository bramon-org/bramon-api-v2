<?php

namespace App\Http\Requests\Api\V2\Public;

use Illuminate\Foundation\Http\FormRequest;

class CaptureListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'page' => 'integer|min:1',
            'per_page' => 'integer|min:1|max:100',
            'station' => 'nullable|string|max:50',
            'date_from' => 'nullable|date_format:Y-m-d',
            'date_to' => 'nullable|date_format:Y-m-d',
            'has_metadata' => 'nullable|boolean',
            'has_preview' => 'nullable|boolean',
            'use_cache' => 'boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'page.min' => 'Page must be at least 1.',
            'per_page.min' => 'Per page must be at least 1.',
            'per_page.max' => 'Per page cannot exceed 100.',
            'date_from.date_format' => 'Date from must be in YYYY-MM-DD format.',
            'date_to.date_format' => 'Date to must be in YYYY-MM-DD format.',
        ];
    }
}