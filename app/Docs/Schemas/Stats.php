<?php

namespace App\Docs\Schemas;

use OpenApi\Attributes as OA;

/**
 * Schemas for the statistics endpoint.
 */
#[OA\Schema(
    schema: 'DateRange',
    description: 'Intervalo de datas coberto pelo índice.',
    properties: [
        new OA\Property(property: 'earliest', type: 'string', nullable: true, example: '2026-06-02 00:04:13'),
        new OA\Property(property: 'latest', type: 'string', nullable: true, example: '2026-09-30 23:51:20'),
    ],
)]
#[OA\Schema(
    schema: 'StatsResponse',
    required: [
        'total_captures',
        'total_stations',
        'total_operators',
        'total_files',
        'date_range',
        'captures_by_station',
        'captures_by_month',
        'last_updated',
    ],
    properties: [
        new OA\Property(property: 'total_captures', type: 'integer', example: 12051),
        new OA\Property(property: 'total_stations', type: 'integer', example: 40),
        new OA\Property(property: 'total_operators', type: 'integer', example: 1),
        new OA\Property(property: 'total_files', type: 'integer', example: 36153),
        new OA\Property(
            property: 'date_range',
            description: 'Intervalo de datas coberto pelo índice.',
            allOf: [new OA\Schema(ref: '#/components/schemas/DateRange')],
        ),
        new OA\Property(
            property: 'captures_by_station',
            type: 'object',
            description: 'Total de capturas por estação.',
            additionalProperties: new OA\AdditionalProperties(type: 'integer'),
            example: ['STA01' => 307, 'STA02' => 298],
        ),
        new OA\Property(
            property: 'captures_by_month',
            type: 'object',
            description: 'Total de capturas por mês, no formato AAAAMM.',
            additionalProperties: new OA\AdditionalProperties(type: 'integer'),
            example: ['202606' => 120, '202607' => 310],
        ),
        new OA\Property(
            property: 'last_updated',
            type: 'string',
            format: 'date-time',
            description: 'Data de geração do índice.',
            example: '2026-09-30T03:30:47+00:00',
        ),
    ],
)]
class Stats
{
}
