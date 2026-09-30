<?php

namespace App\Docs;

use OpenApi\Attributes as OA;

/**
 * Root OpenAPI definition.
 *
 * This class carries no runtime behaviour; it only holds the top-level
 * attributes (info, servers, tags) that swagger-php requires. Without an
 * OA\Info somewhere in the scan paths, `l5-swagger:generate` aborts with
 * "Required @OA\Info() not found".
 *
 * Attributes are used rather than docblock annotations: swagger-php 6.11
 * deprecated the docblock parser, and the Doctrine parser underneath it
 * cannot express nested array literals (`enum={...}`, `items={...}`,
 * `additionalProperties={...}`), which these schemas need.
 */
#[OA\OpenApi(
    info: new OA\Info(
        title: 'BRAMON API',
        version: '2.0.0',
        description: <<<'TXT'
            API REST para acesso às capturas das estações do projeto BRAMON.

            Todas as rotas de listagem são servidas a partir de um índice pré-construído
            (`php artisan bramon:index`), portanto o custo de memória de cada requisição
            é independente da quantidade de arquivos no disco. Se o índice não existir,
            as rotas retornam 503 com uma mensagem indicando o comando a executar.
            TXT,
        contact: new OA\Contact(
            name: 'BRAMON',
            email: 'suporte@bramonmeteor.org',
            url: 'https://bramonmeteor.org',
        ),
        license: new OA\License(
            name: 'MIT',
            url: 'https://opensource.org/licenses/MIT',
        ),
    ),
    servers: [
        new OA\Server(
            url: '/api/v2/public',
            description: 'API pública v2 (caminho relativo ao host)',
        ),
    ],
    tags: [
        new OA\Tag(name: 'Health', description: 'Estado da API, do diretório de dados e do índice de capturas'),
        new OA\Tag(name: 'Captures', description: 'Capturas e seus arquivos associados (metadados, preview, thumbnail)'),
        new OA\Tag(name: 'Stations', description: 'Estações meteorológicas, suas capturas e estatísticas'),
        new OA\Tag(name: 'Operators', description: 'Operadores e as estações sob sua responsabilidade'),
        new OA\Tag(name: 'Statistics', description: 'Estatísticas agregadas de capturas, estações e operadores'),
        new OA\Tag(name: 'Cache', description: 'Invalidação do índice de capturas em disco'),
    ],
)]
class OpenApi
{
}
