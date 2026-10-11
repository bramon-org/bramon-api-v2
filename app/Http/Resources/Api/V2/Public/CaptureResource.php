<?php

namespace App\Http\Resources\Api\V2\Public;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CaptureResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this['id'],
            'station' => $this['station'],
            'captured_at' => $this['captured_at'],
            'capture_date' => $this['capture_date'],
            'capture_time' => $this['capture_time'],
            'observer' => $this['observer'] ?? null,
            'files' => $this['files'],
            'file_count' => $this['file_count'],
            'has_metadata' => $this['has_metadata'],
            'has_preview' => $this['has_preview'],
            'has_thumbnail' => $this['has_thumbnail'],
            'class' => $this['class'],
            'fs' => $this['fs'],
            'fe' => $this['fe'],
            'sec' => $this['sec'],
            'av' => $this['av'],
            'mag' => $this['mag'],
            'analyzed' => $this['analyzed'],
        ];
    }
}