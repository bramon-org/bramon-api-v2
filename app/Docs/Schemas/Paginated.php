<?php

namespace App\Docs\Schemas;

use OpenApi\Attributes as OA;

/**
 * Paginated collection envelopes.
 *
 * PaginatedResource emits a *flat* envelope: the items live under `data` and the
 * pagination counters are siblings, not nested under a `meta` key:
 *
 *     {
 *       "data": [...],
 *       "total": 12051, "page": 1, "per_page": 15, "total_pages": 804,
 *       "has_next": true, "has_prev": false
 *     }
 *
 * The pagination block is repeated across the three envelopes instead of being
 * factored into a shared `$ref` because OpenAPI 3.0 offers no way to splice a
 * property list into another schema; `allOf` merges whole schemas, which would
 * have made the envelope indistinguishable from the item schema.
 */
#[OA\Schema(
    schema: 'PaginatedCaptures',
    description: 'Envelope paginado de capturas, ordenadas por captured_at descendente.',
    required: ['data', 'total', 'page', 'per_page', 'total_pages', 'has_next', 'has_prev'],
    properties: [
        new OA\Property(
            property: 'data',
            type: 'array',
            description: 'Capturas da página atual.',
            items: new OA\Items(ref: '#/components/schemas/Capture'),
        ),
        new OA\Property(property: 'total', type: 'integer', description: 'Total de capturas que atendem ao filtro.', example: 12051),
        new OA\Property(property: 'page', type: 'integer', description: 'Página atual, base 1.', example: 1),
        new OA\Property(property: 'per_page', type: 'integer', example: 15),
        new OA\Property(property: 'total_pages', type: 'integer', example: 804),
        new OA\Property(property: 'has_next', type: 'boolean', example: true),
        new OA\Property(property: 'has_prev', type: 'boolean', example: false),
    ],
)]
#[OA\Schema(
    schema: 'PaginatedStations',
    description: 'Envelope paginado de estações.',
    required: ['data', 'total', 'page', 'per_page', 'total_pages', 'has_next', 'has_prev'],
    properties: [
        new OA\Property(
            property: 'data',
            type: 'array',
            description: 'Estações da página atual.',
            items: new OA\Items(ref: '#/components/schemas/Station'),
        ),
        new OA\Property(property: 'total', type: 'integer', description: 'Total de estações que atendem ao filtro.', example: 40),
        new OA\Property(property: 'page', type: 'integer', description: 'Página atual, base 1.', example: 1),
        new OA\Property(property: 'per_page', type: 'integer', example: 15),
        new OA\Property(property: 'total_pages', type: 'integer', example: 3),
        new OA\Property(property: 'has_next', type: 'boolean', example: true),
        new OA\Property(property: 'has_prev', type: 'boolean', example: false),
    ],
)]
#[OA\Schema(
    schema: 'PaginatedOperators',
    description: 'Envelope paginado de operadores.',
    required: ['data', 'total', 'page', 'per_page', 'total_pages', 'has_next', 'has_prev'],
    properties: [
        new OA\Property(
            property: 'data',
            type: 'array',
            description: 'Operadores da página atual.',
            items: new OA\Items(ref: '#/components/schemas/Operator'),
        ),
        new OA\Property(property: 'total', type: 'integer', description: 'Total de operadores que atendem ao filtro.', example: 1),
        new OA\Property(property: 'page', type: 'integer', description: 'Página atual, base 1.', example: 1),
        new OA\Property(property: 'per_page', type: 'integer', example: 15),
        new OA\Property(property: 'total_pages', type: 'integer', example: 1),
        new OA\Property(property: 'has_next', type: 'boolean', example: false),
        new OA\Property(property: 'has_prev', type: 'boolean', example: false),
    ],
)]
class Paginated
{
}
