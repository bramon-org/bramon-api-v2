<?php

namespace App\Docs\Schemas;

use OpenApi\Attributes as OA;

/**
 * Schemas for the health endpoint.
 */
#[OA\Schema(
    schema: 'IndexStatus',
    description: 'Estado do índice de capturas em disco.',
    required: ['built', 'total_captures', 'total_stations', 'disk_usage'],
    properties: [
        new OA\Property(
            property: 'built',
            type: 'boolean',
            description: 'Indica se o índice está disponível.',
            example: true,
        ),
        new OA\Property(
            property: 'generated_at',
            type: 'string',
            format: 'date-time',
            nullable: true,
            example: '2026-09-30T03:30:47+00:00',
        ),
        new OA\Property(property: 'total_captures', type: 'integer', example: 12051),
        new OA\Property(property: 'total_stations', type: 'integer', example: 40),
        new OA\Property(
            property: 'disk_usage',
            type: 'integer',
            description: 'Tamanho do índice em bytes.',
            example: 11333890,
        ),
    ],
)]
#[OA\Schema(
    schema: 'MemoryStatus',
    description: 'Consumo de memória do processo que atendeu a requisição, em bytes.',
    required: ['current', 'peak'],
    properties: [
        new OA\Property(property: 'current', type: 'integer', example: 8388608),
        new OA\Property(property: 'peak', type: 'integer', example: 8388608),
        new OA\Property(
            property: 'limit',
            type: 'integer',
            nullable: true,
            description: 'memory_limit em bytes; null quando ilimitado.',
            example: 67108864,
        ),
    ],
)]
#[OA\Schema(
    schema: 'HealthResponse',
    required: ['status', 'timestamp', 'base_dir', 'base_dir_exists', 'index', 'memory'],
    properties: [
        new OA\Property(
            property: 'status',
            type: 'string',
            enum: ['healthy', 'degraded'],
            example: 'healthy',
        ),
        new OA\Property(property: 'timestamp', type: 'string', format: 'date-time', example: '2026-09-30T03:30:47+00:00'),
        new OA\Property(property: 'base_dir', type: 'string', example: '/mnt/dados/BRAMON'),
        new OA\Property(property: 'base_dir_exists', type: 'boolean', example: true),
        new OA\Property(
            property: 'index',
            description: 'Estado do índice de capturas.',
            allOf: [new OA\Schema(ref: '#/components/schemas/IndexStatus')],
        ),
        new OA\Property(
            property: 'memory',
            description: 'Consumo de memória do processo.',
            allOf: [new OA\Schema(ref: '#/components/schemas/MemoryStatus')],
        ),
        new OA\Property(
            property: 'hint',
            type: 'string',
            nullable: true,
            description: 'Presente apenas quando status é degraded, indicando a ação necessária.',
            example: 'Índice não construído. Execute: php artisan bramon:index',
        ),
    ],
)]
class Health
{
}
