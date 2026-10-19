<?php

namespace App\Docs\Schemas;

use OpenApi\Attributes as OA;

/**
 * Schemas for captures, stations and operators.
 *
 * The field lists below mirror the JsonResource classes under
 * app/Http/Resources/Api/V2/Public, so the docs stay aligned with the actual
 * API response payloads.
 */
#[OA\Schema(
    schema: 'CaptureFile',
    description: 'Um arquivo associado a uma captura.',
    required: ['url', 'type', 'filename', 'extension'],
    properties: [
        new OA\Property(
            property: 'url',
            type: 'string',
            description: 'Caminho relativo ao diretório base de dados.',
            example: 'STA32/2026/202609/20260930/M20260930_235120_STA32_1T.jpg',
        ),
        new OA\Property(
            property: 'type',
            type: 'string',
            enum: ['metadata', 'preview', 'thumbnail', 'analyze'],
            example: 'thumbnail',
        ),
        new OA\Property(property: 'filename', type: 'string', example: 'M20260930_235120_STA32_1T.jpg'),
        new OA\Property(property: 'extension', type: 'string', example: 'jpg'),
        new OA\Property(property: 'size', type: 'integer', nullable: true, example: 4143),
        new OA\Property(property: 'modified_at', type: 'string', format: 'date-time', nullable: true),
    ],
)]
#[OA\Schema(
    schema: 'Capture',
    required: ['id', 'station', 'captured_at', 'capture_date', 'capture_time', 'observer', 'files', 'file_count'],
    properties: [
        new OA\Property(
            property: 'id',
            type: 'string',
            description: 'md5 truncado de station_date_time.',
            example: 'e99854fa685b4adf23084bc9200f0fcb',
        ),
        new OA\Property(property: 'station', type: 'string', example: 'STA32'),
        new OA\Property(property: 'captured_at', type: 'string', example: '2026-09-30 23:51:20'),
        new OA\Property(property: 'capture_date', type: 'string', example: '20260930'),
        new OA\Property(property: 'capture_time', type: 'string', example: '235120'),
        new OA\Property(property: 'observer', type: 'string', nullable: true, example: 'Observer Name'),
        new OA\Property(
            property: 'files',
            type: 'array',
            description: 'Arquivos associados à captura.',
            items: new OA\Items(ref: '#/components/schemas/CaptureFile'),
        ),
        new OA\Property(property: 'file_count', type: 'integer', example: 3),
        new OA\Property(property: 'has_metadata', type: 'boolean', example: true),
        new OA\Property(property: 'has_preview', type: 'boolean', example: true),
        new OA\Property(property: 'has_thumbnail', type: 'boolean', example: true),
        new OA\Property(
            property: 'has_analyze',
            type: 'boolean',
            description: 'Indica que a captura possui arquivo de análise (sufixo A.xml).',
            example: true,
        ),
        new OA\Property(
            property: 'class',
            type: 'string',
            nullable: true,
            description: 'Classificação da trail registrada no XML de análise (ufoanalyzer_record.ua2_object.class). Null quando a captura não possui análise.',
            example: 'J8_PPH',
        ),
        new OA\Property(
            property: 'fs',
            type: 'number',
            format: 'double',
            nullable: true,
            description: 'Frame inicial da trail, lido do XML de análise.',
            example: 61.0,
        ),
        new OA\Property(
            property: 'fe',
            type: 'number',
            format: 'double',
            nullable: true,
            description: 'Frame final da trail, lido do XML de análise.',
            example: 70.0,
        ),
        new OA\Property(
            property: 'sec',
            type: 'number',
            format: 'double',
            nullable: true,
            description: 'Duração da trail em segundos, lida do XML de análise.',
            example: 0.15015,
        ),
        new OA\Property(
            property: 'av',
            type: 'number',
            format: 'double',
            nullable: true,
            description: 'Velocidade angular média da trail, lida do XML de análise.',
            example: 5.019607,
        ),
        new OA\Property(
            property: 'mag',
            type: 'number',
            format: 'double',
            nullable: true,
            description: 'Magnitude estimada da trail, lida do XML de análise.',
            example: -1.838501,
        ),
        new OA\Property(
            property: 'analyzed',
            type: 'boolean',
            description: 'Indica que o XML de análise da captura trouxe pelo menos um destes dados.',
            example: true,
        ),
    ],
)]
#[OA\Schema(
    schema: 'Station',
    required: ['id', 'name', 'country', 'active', 'visible', 'capture_count'],
    properties: [
        new OA\Property(
            property: 'id',
            type: 'string',
            description: 'md5 do nome da estação.',
            example: 'd15605f23fa41195af8ca4626d43ef5e',
        ),
        new OA\Property(property: 'name', type: 'string', example: 'STA01'),
        new OA\Property(
            property: 'operador',
            description: 'Operador responsável pela estação, no mesmo formato de /operators. Resolvido pelo nome da estação; null quando não há operador vinculado.',
            allOf: [new OA\Items(ref: '#/components/schemas/Operator')],
            nullable: true,
        ),
        new OA\Property(property: 'city', type: 'string', example: 'São Paulo'),
        new OA\Property(property: 'state', type: 'string', example: 'SP'),
        new OA\Property(property: 'country', type: 'string', example: 'BR'),
        new OA\Property(property: 'latitude', type: 'number', format: 'double', example: -23.5505),
        new OA\Property(property: 'longitude', type: 'number', format: 'double', example: -46.6333),
        new OA\Property(
            property: 'altitude',
            type: 'number',
            format: 'double',
            nullable: true,
            description: 'Altitude em metros, lida da captura mais recente com posicionamento válido. Null quando nenhuma captura registrou.',
            example: 12.0,
        ),
        new OA\Property(
            property: 'azimuth',
            type: 'number',
            format: 'double',
            description: 'Apenas curado em stations.json: nenhuma captura registra azimute.',
            example: 180.0,
        ),
        new OA\Property(
            property: 'elevation',
            type: 'number',
            format: 'double',
            description: 'Apenas curado em stations.json: nenhuma captura registra elevação.',
            example: 760.0,
        ),
        new OA\Property(property: 'fov', type: 'number', format: 'double', example: 60.0),
        new OA\Property(property: 'camera_model', type: 'string', example: 'Canon EOS'),
        new OA\Property(property: 'camera_lens', type: 'string', example: 'Wide Angle'),
        new OA\Property(property: 'camera_capture', type: 'string', example: 'Unknown'),
        new OA\Property(property: 'active', type: 'boolean', example: true),
        new OA\Property(property: 'visible', type: 'boolean', example: true),
        new OA\Property(
            property: 'capture_count',
            type: 'integer',
            description: 'Total de capturas, servido a partir do índice.',
            example: 307,
        ),
        new OA\Property(property: 'first_capture', type: 'string', nullable: true, example: '2026-06-02 05:51:06'),
        new OA\Property(property: 'last_capture', type: 'string', nullable: true, example: '2026-09-30 08:44:23'),
    ],
)]
#[OA\Schema(
    schema: 'Operator',
    required: ['id', 'name', 'email', 'role', 'visible', 'active', 'station_count', 'stations'],
    properties: [
        new OA\Property(property: 'id', type: 'string', example: 'TLP'),
        new OA\Property(property: 'name', type: 'string', example: 'THIAGO_PAES'),
        new OA\Property(property: 'email', type: 'string', format: 'email', example: 'thiago.paes@bramonmeteor.org'),
        new OA\Property(property: 'role', type: 'string', example: 'operator'),
        new OA\Property(property: 'visible', type: 'boolean', example: true),
        new OA\Property(property: 'active', type: 'boolean', example: true),
        new OA\Property(property: 'station_count', type: 'integer', example: 10),
        new OA\Property(
            property: 'stations',
            type: 'array',
            description: 'Códigos das estações sob responsabilidade do operador.',
            items: new OA\Items(type: 'string'),
            example: ['TLP1', 'TLP2'],
        ),
        new OA\Property(
            property: 'latitude',
            type: 'number',
            format: 'double',
            nullable: true,
            description: 'Posição medida na primeira estação de `stations`. Nenhuma captura registra onde o operador mora, então a coordenada é a da estação.',
            example: -27.5904,
        ),
        new OA\Property(
            property: 'longitude',
            type: 'number',
            format: 'double',
            nullable: true,
            example: -48.5925,
        ),
        new OA\Property(
            property: 'altitude',
            type: 'number',
            format: 'double',
            nullable: true,
            description: 'Altitude em metros, da mesma captura da latitude/longitude.',
            example: 12.0,
        ),
    ],
)]
#[OA\Schema(
    schema: 'StationStats',
    description: 'Estatísticas de uma estação, derivadas dos agregados do índice.',
    required: ['station', 'total_captures'],
    properties: [
        new OA\Property(property: 'station', type: 'string', example: 'STA07'),
        new OA\Property(property: 'total_captures', type: 'integer', example: 266),
        new OA\Property(property: 'first_capture', type: 'string', nullable: true, example: '2026-06-03 00:08:54'),
        new OA\Property(property: 'last_capture', type: 'string', nullable: true, example: '2026-09-29 17:09:04'),
        new OA\Property(
            property: 'captures_by_month',
            type: 'object',
            description: 'Total de capturas por mês, no formato AAAAMM.',
            additionalProperties: new OA\AdditionalProperties(type: 'integer'),
            example: ['202606' => 90, '202607' => 90, '202608' => 86],
        ),
    ],
)]
class Resources
{
}
