<?php

namespace App\Docs\Schemas;

use OpenApi\Attributes as OA;

/**
 * Error and index-state schemas shared by every endpoint.
 *
 * Declared as empty classes: they exist only to hold OA\Schema attributes and
 * are never instantiated.
 *
 * Note: OA\Schema cannot be applied to a PHP property, so each schema is
 * self-contained and nests its own OA\Property list.
 */
#[OA\Schema(
    schema: 'Error',
    description: 'Formato de erro padrão da API.',
    required: ['detail'],
    properties: [
        new OA\Property(
            property: 'detail',
            type: 'string',
            description: 'Mensagem legível do erro.',
            example: 'Estação não encontrada',
        ),
        new OA\Property(
            property: 'errors',
            type: 'object',
            nullable: true,
            description: 'Presente apenas em falhas de validação (HTTP 422). Mapeia o nome do campo para a lista de mensagens.',
            additionalProperties: new OA\AdditionalProperties(
                type: 'array',
                items: new OA\Items(type: 'string'),
            ),
            example: ['per_page' => ['O campo per page não pode ser maior que 100.']],
        ),
    ],
)]
#[OA\Schema(
    schema: 'IndexNotReady',
    description: 'Resposta 503 emitida enquanto o índice de capturas não foi construído. As rotas de listagem devolvem este corpo em vez de varrer o disco, o que estouraria o limite de memória do servidor.',
    required: ['detail'],
    properties: [
        new OA\Property(
            property: 'detail',
            type: 'string',
            example: 'Índice de capturas não construído. Execute: php artisan bramon:index',
        ),
    ],
)]
class Common
{
}
