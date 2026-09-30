<?php

namespace App\Http\Resources\Api\V2\Public;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StatsResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'total_captures' => $this['total_captures'],
            'total_stations' => $this['total_stations'],
            'total_operators' => $this['total_operators'],
            'total_files' => $this['total_files'],
            'date_range' => $this['date_range'],
            'captures_by_station' => $this['captures_by_station'],
            'captures_by_month' => $this['captures_by_month'],
            'last_updated' => $this['last_updated'],
        ];
    }
}