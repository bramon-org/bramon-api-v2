<?php

namespace App\Http\Resources\Api\V2\Public;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StationResource extends JsonResource
{
    /**
     * The operator owning this station, or null when the station is unowned.
     *
     * Resolved through OperatorResource so the nested object is byte-for-byte the
     * same payload as the one served by /operators.
     */
    protected function operador(Request $request): ?array
    {
        $operator = $this->resource['operador'] ?? null;

        if (!is_array($operator)) {
            return null;
        }

        return (new OperatorResource($operator))->resolve($request);
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this['id'],
            'name' => $this['name'],
            'operador' => $this->operador($request),
            'city' => $this['city'],
            'state' => $this['state'],
            'country' => $this['country'],
            'latitude' => $this['latitude'],
            'longitude' => $this['longitude'],
            'azimuth' => $this['azimuth'],
            'elevation' => $this['elevation'],
            'fov' => $this['fov'],
            'camera_model' => $this['camera_model'],
            'camera_lens' => $this['camera_lens'],
            'camera_capture' => $this['camera_capture'],
            'active' => $this['active'],
            'visible' => $this['visible'],
            'capture_count' => $this['capture_count'],
            'first_capture' => $this['first_capture'],
            'last_capture' => $this['last_capture'],
        ];
    }
}