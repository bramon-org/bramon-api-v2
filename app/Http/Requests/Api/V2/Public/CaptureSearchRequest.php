<?php

namespace App\Http\Requests\Api\V2\Public;

class CaptureSearchRequest extends CaptureListRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'q' => 'required|string|min:1|max:200',
            'stations' => 'sometimes|array|max:50',
            'stations.*' => 'string|max:50',
        ]);
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'q.required' => 'Search query is required.',
            'q.min' => 'Search query must be at least 1 character.',
        ]);
    }
}