<?php

namespace App\Http\Requests\Api\V2\Public;

use Illuminate\Foundation\Http\FormRequest;

class StatsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'use_cache' => 'boolean',
        ];
    }
}