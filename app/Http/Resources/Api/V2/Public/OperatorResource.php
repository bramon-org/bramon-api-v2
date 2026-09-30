<?php

namespace App\Http\Resources\Api\V2\Public;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OperatorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this['id'],
            'name' => $this['name'],
            'email' => $this['email'],
            'role' => $this['role'],
            'visible' => $this['visible'],
            'active' => $this['active'],
            'station_count' => $this['station_count'],
            'stations' => $this['stations'],
        ];
    }
}