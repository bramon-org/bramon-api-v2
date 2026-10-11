<?php

namespace App\Docs\Parameters;

use OpenApi\Attributes as OA;

/**
 * Reusable query parameters.
 *
 * Declared once here and referenced from the controllers by name, so the
 * pagination contract stays consistent across every listing endpoint.
 *
 * Note: `per_page` is validated with `max:100`, so a larger value produces a
 * 422 rather than being silently clamped.
 */
#[OA\Parameter(
    parameter: 'page',
    name: 'page',
    in: 'query',
    description: 'Página, base 1.',
    required: false,
    schema: new OA\Schema(type: 'integer', minimum: 1, default: 1, example: 1),
)]
#[OA\Parameter(
    parameter: 'per_page',
    name: 'per_page',
    in: 'query',
    description: 'Itens por página. Máximo aceito: 100; acima disso a requisição é rejeitada com 422.',
    required: false,
    schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 15, example: 15),
)]
#[OA\Parameter(
    parameter: 'active_only',
    name: 'active_only',
    in: 'query',
    description: 'Quando verdadeiro, devolve somente itens marcados como ativos.',
    required: false,
    schema: new OA\Schema(type: 'boolean', default: false, example: false),
)]
#[OA\Parameter(
    parameter: 'station',
    name: 'station',
    in: 'query',
    description: 'Filtra capturas pelo código da estação (comparação exata).',
    required: false,
    schema: new OA\Schema(type: 'string', maxLength: 50, example: 'STA07'),
)]
#[OA\Parameter(
    parameter: 'date_from',
    name: 'date_from',
    in: 'query',
    description: 'Capturas com data igual ou posterior a este valor (inclusive).',
    required: false,
    schema: new OA\Schema(type: 'string', format: 'date', example: '2026-09-01'),
)]
#[OA\Parameter(
    parameter: 'date_to',
    name: 'date_to',
    in: 'query',
    description: 'Capturas com data igual ou anterior a este valor (inclusive).',
    required: false,
    schema: new OA\Schema(type: 'string', format: 'date', example: '2026-09-30'),
)]
#[OA\Parameter(
    parameter: 'has_metadata',
    name: 'has_metadata',
    in: 'query',
    description: 'Filtra capturas que possuem (ou não) arquivo de metadados.',
    required: false,
    schema: new OA\Schema(type: 'boolean', example: true),
)]
#[OA\Parameter(
    parameter: 'has_preview',
    name: 'has_preview',
    in: 'query',
    description: 'Filtra capturas que possuem (ou não) imagem de preview.',
    required: false,
    schema: new OA\Schema(type: 'boolean', example: true),
)]
#[OA\Parameter(
    parameter: 'use_cache',
    name: 'use_cache',
    in: 'query',
    description: 'Reservado para cache de resposta; hoje a listagem já é servida do índice em disco.',
    required: false,
    schema: new OA\Schema(type: 'boolean', default: true, example: true),
)]
#[OA\Parameter(
    parameter: 'q',
    name: 'q',
    in: 'query',
    description: 'Termo de busca livre (estação, identificador ou caminho).',
    required: true,
    schema: new OA\Schema(type: 'string', minLength: 1, maxLength: 200, example: 'STA07'),
)]
class Common
{
}
