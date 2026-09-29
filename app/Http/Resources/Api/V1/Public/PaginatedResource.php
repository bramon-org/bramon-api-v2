<?php

namespace App\Http\Resources\Api\V1\Public;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaginatedResource extends JsonResource
{
    public function __construct(
        public array $data,
        public int $total,
        public int $page,
        public int $perPage,
        public int $totalPages,
        public bool $hasNext,
        public bool $hasPrev
    ) {}

    public function toArray(Request $request): array
    {
        return [
            'data' => $this->data,
            'total' => $this->total,
            'page' => $this->page,
            'per_page' => $this->perPage,
            'total_pages' => $this->totalPages,
            'has_next' => $this->hasNext,
            'has_prev' => $this->hasPrev,
        ];
    }
}