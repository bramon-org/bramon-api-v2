# BRAMON API v2.0 (Laravel)

[![Tests](https://github.com/bramon-org/bramon-api-v2/actions/workflows/laravel.yml/badge.svg)](https://github.com/bramon-org/bramon-api-v2/actions/workflows/laravel.yml)

API REST performática para consulta de capturas de meteoros da rede BRAMON (Brazilian Meteor Observation Network), desenvolvida em Laravel 11 + PHP 8.4.

## 📋 Visão Geral

Esta API lê arquivos em tempo real do filesystem (sem banco de dados), fornecendo endpoints para consultar:
- Capturas de meteoros
- Estações de observação
- Operadores
- Estatísticas gerais

## 🚀 Requisitos

- PHP 8.4+
- Composer 2.x
- Docker (opcional, para produção)

## 📦 Instalação

### Desenvolvimento Local

```bash
cd bramon-api-novo
cp .env.example .env
composer install
php artisan key:generate

# Configure as variáveis de ambiente no .env
# BRAMON_BASE_DIR=/mnt/dados/BRAMON
# BRAMON_OPERATORS_FILE=/mnt/dados/BRAMON/operators.json
# BRAMON_STATIONS_FILE=/mnt/dados/BRAMON/stations.json

php artisan serve --host=0.0.0.0 --port=8080
```

### Produção com Docker

```bash
cd bramon-api-novo
cp .env.example .env
# Edite o .env com suas configurações
docker-compose up -d
```

## ⚙️ Configuração

### Variáveis de Ambiente (.env)

| Variável | Descrição | Padrão |
|----------|-----------|--------|
| `APP_NAME` | Nome da aplicação | BRAMON API |
| `APP_ENV` | Ambiente (local/production) | local |
| `APP_DEBUG` | Modo debug | true |
| `APP_URL` | URL base | http://localhost:8080 |
| `BRAMON_BASE_DIR` | Diretório base das capturas | /mnt/dados/BRAMON |
| `BRAMON_OPERATORS_FILE` | Arquivo JSON de operadores | /mnt/dados/BRAMON/operators.json |
| `BRAMON_STATIONS_FILE` | Arquivo JSON de estações | /mnt/dados/BRAMON/stations.json |
| `BRAMON_CACHE_TTL` | TTL do cache em segundos | 60 |
| `BRAMON_MAX_RESULTS` | Máximo de resultados por página | 1000 |

## 📁 Estrutura de Diretórios Esperada

```
BRAMON_BASE_DIR/
├── TLP1/
│   ├── 2025/
│   │   ├── 202508/
│   │   │   ├── 20250801/
│   │   │   │   ├── M20250802_034905_TLP_1.xml
│   │   │   │   ├── M20250802_034905_TLP_1P.jpg
│   │   │   │   └── M20250802_034905_TLP_1T.jpg
├── TLP2/
│   └── ...
├── operators.json
└── stations.json
```

### Padrão de Arquivo
`M{YYYYMMDD}_{HHMMSS}_{ESTACAO}_{TIPO}.{ext}`

| Sufixo | Tipo |
|--------|------|
| (nenhum) ou 1 | metadata (XML) |
| P | preview (JPG) |
| T | thumbnail (JPG/BMP) |
| A | analyze (XML) |

### Índice de capturas (`bramon:index`)

As rotas de listagem (`/captures`, `/stations`, `/operators`, `/stats`) são servidas a
partir de um índice pré-construído em disco, para que o custo de memória de cada
requisição não dependa da quantidade de arquivos na árvore.

```bash
php artisan bramon:index --force
```

Por padrão o comando **substitui** o índice: ele varre a árvore inteira e publica
um índice novo. Com `--acumulative` o comando **mescla** a varredura com o índice
já publicado:

```bash
php artisan bramon:index --acumulative --since-days=30
```

| Opção | Efeito |
|-------|--------|
| `--acumulative` | Mescla a varredura com o índice existente em vez de substituí-lo |
| `--since-days=N` / `--since=AAAA-MM-DD` | Restringe a **varredura** a uma janela de data |
| `--no-prune` | Com `--acumulative`, mantém as capturas cujos arquivos sumiram do disco |
| `--resume` / `--fresh` | Retoma um build interrompido (checkpoint por estação) |
| `--force` | Refaz o índice mesmo que ele já exista |
| `--flush` | Apaga o índice |

No modo acumulativo:

- as capturas dentro da janela são lidas do disco e **substituem** o que o índice
  tinha (uma captura que ganhou um `A.xml` de análise passa a exibi-lo);
- as capturas fora da janela são **carried over** (mantidas como estão);
- os arquivos que sumiram do disco saem do registro, e uma captura que ficou sem
  nenhum arquivo sai do índice;
- o `operator` e a `geolocation` de uma estação que a janela não tocou continuam
  vindo do manifesto anterior — sem isso, um build parcial apagaria o operador da
  estação.

> O `--since` vale só para a varredura: o índice final cobre a janela **e** o que
> já estava indexado. Fora da janela só a existência dos arquivos é verificada —
> alteração de conteúdo é lida no próximo build que cobrir a data da captura (e
> contada em `stale` no manifesto, como aviso).

O manifesto registra como o índice foi montado:

```json
{
  "accumulative": true,
  "carried_over": 128374,
  "pruned_files": 12,
  "pruned_captures": 3,
  "stale": 0
}
```

## 📚 Endpoints da API

Base URL: `http://localhost:8080/api/v2/public`

### Health & Sistema

| Método | Endpoint | Descrição |
|--------|----------|-----------|
| GET | `/health` | Health check |
| GET | `/stats` | Estatísticas gerais |
| POST | `/cache/invalidate` | Invalida cache |

### Capturas

| Método | Endpoint | Descrição |
|--------|----------|-----------|
| GET | `/captures` | Lista capturas (paginado) |
| GET | `/captures/search` | Busca capturas |
| GET | `/captures/{id}` | Detalhes da captura |
| GET | `/captures/{id}/files/{type}` | Download do arquivo |

### Estações

| Método | Endpoint | Descrição |
|--------|----------|-----------|
| GET | `/stations` | Lista estações (paginado) |
| GET | `/stations/{name}` | Detalhes da estação |
| GET | `/stations/{name}/captures` | Capturas da estação |
| GET | `/stations/{name}/stats` | Estatísticas da estação |

### Operadores

| Método | Endpoint | Descrição |
|--------|----------|-----------|
| GET | `/operators` | Lista operadores (paginado) |
| GET | `/operators/{id}` | Detalhes do operador |

## 📖 Exemplos de Uso

```bash
# Health check
curl http://localhost:8080/api/v2/public/health

# Estatísticas gerais
curl http://localhost:8080/api/v2/public/stats

# Listar capturas (página 1, 15 itens)
curl "http://localhost:8080/api/v2/public/captures?page=1&per_page=15"

# Capturas da estação TLP1 em agosto 2025
curl "http://localhost:8080/api/v2/public/captures?station=TLP1&date_from=2025-08-01&date_to=2025-08-31"

# Buscar capturas
curl "http://localhost:8080/api/v2/public/captures/search?q=TLP1&limit=10"

# Listar estações
curl http://localhost:8080/api/v2/public/stations

# Capturas de uma estação
curl "http://localhost:8080/api/v2/public/stations/TLP1/captures"

# Estatísticas da estação
curl "http://localhost:8080/api/v2/public/stations/TLP1/stats"

# Listar operadores
curl http://localhost:8080/api/v2/public/operators

# Invalida cache
curl -X POST http://localhost:8080/api/v2/public/cache/invalidate
```

## 🔧 Arquivos de Dados

### operators.json
```json
[
  {
    "id": "uuid-do-operador",
    "nome": "Nome do Operador",
    "email": "email@dominio.com",
    "visivel": true,
    "ativo": true,
    "estacao": "TLP",
    "estacoes": ["TLP1", "TLP2", "TLP3"]
  }
]
```

**Prioridade de vinculação:**
1. Campo `estacoes` (lista explícita) - **prioridade máxima**
2. Campo `estacao` (prefixo) - fallback

### Ligação estação → operador

O nó `operador` de `GET /stations` e `GET /stations/{name}` devolve o **operador
responsável como objeto**, com exatamente o mesmo formato de `GET /operators`:

```json
{
  "id": "TLP",
  "name": "THIAGO_PAES",
  "email": "thiago.paes@bramonmeteor.org",
  "role": "operator",
  "visible": true,
  "active": true,
  "station_count": 10,
  "stations": ["TLP1", "TLP10", "TLP2"],
  "latitude": -27.5904,
  "longitude": -48.5925,
  "altitude": 12.0
}
```

O de-para é feito **a partir do nome da estação**, invertendo as mesmas regras de
`operators.json`, de modo que `/stations` e `/operators` nunca discordam sobre quem
possui cada estação. Para cada estação, nesta ordem:

1. a lista `estacoes` do operador contém o nome da estação
2. o nome da estação começa com o prefixo `estacao` do operador
3. o campo `operador` da própria `stations.json` bate com o `id` de um operador

Um operador com lista `estacoes` explícita nunca "vaza" pelo seu prefixo. Quando
nenhuma regra casa, `operador` retorna `null`.

### Ligação quando `operators.json` está ausente ou vazio

`operators.json` continua sendo a fonte autoritativa. Sem ele, `/operators` e
`/stations` sintetizam um operador por estação, mas o nome e o e-mail saem do
observador lido do XML das capturas dessa estação:

```json
{ "id": "9df799e2…", "name": "THIAGO_PAES", "email": "thiago.paes@bramonmeteor.org", "stations": ["TLP7"] }
```

Quem popula isso é o índice: `bramon:index` lê a tag `<ufocapture_record>` de cada
captura (`observer` = nome, `lid` = código) e grava o resultado em
`station_aggregates[estação].operator` no `manifest.json`. Nenhuma captura tem
campo de e-mail, então o endereço é derivado do nome — a mesma regra usada por
`bramon:operators-scan`. Uma estação cujas capturas não nomeiam ninguém continua
recebendo o par genérico `Operador {ESTACAO}` / `{estacao}@bramonmeteor.org`.

> Como o manifesto passou a guardar `operator`, um índice no formato anterior é
> ignorado (a versão do formato é validada na leitura). Rode `bramon:index` após
> atualizar.

### Geolocalização

A mesma tag `<ufocapture_record>` carrega `lat`, `lng` e `alt`. O `bramon:index`
lê os três, descarta a posição inutilizável — ausente, não numérica, fora de
`-90..90`/`-180..180`, ou o `0/0` que uma estação sem fixo GPS grava — e guarda em
`station_aggregates[estação].geolocation` a da **captura mais recente que fixou
posição**, não simplesmente a da última captura. Como o passe de agregação percorre
as capturas da mais nova para a mais antiga, basta guardar a primeira que serve.

Isso é uma medição, então latitude, longitude e altitude têm prioridade sobre o
`stations.json`:

| Endpoint | Campo | Origem |
|---|---|---|
| `/stations` | `latitude`, `longitude` | última captura válida da estação; senão `stations.json` |
| `/stations` | `altitude` (**novo**) | `alt` da última captura válida; senão `stations.json._metadata.altitude` |
| `/operators` | `latitude`, `longitude`, `altitude` (**novos**) | mesma captura da **primeira** estação de `stations` |

`azimuth` e `elevation` **não** saem das capturas: nenhum dos 32.903 XMLs de
metadados tem esses atributos, então continuam vindos do `stations.json` — onde
estão `0` para todas as 10 estações. Para a altura do ponto de observação use
`altitude`.

O operador não tem posição própria em lugar nenhum: nenhum XML registra onde a
pessoa mora. A coordenada devolvida é a da primeira estação da lista `stations`
dele, na ordem declarada no `operators.json` (não alfabética). Os três campos
sempre estão presentes e são `null` quando essa estação ainda não fixou posição.

Como o manifesto passou a guardar também `geolocation`, um índice no formato
anterior é ignorado (a versão do formato é validada na leitura). Rode
`bramon:index` após atualizar.

### Análise da captura (arquivo `A.xml`)

O UFOAnalyzer grava, ao lado de cada captura, um XML de análise com o sufixo `A`
(`M20190712_091431_TLP_5A.XML`). É nele que ficam a **classificação da trail** e
os parâmetros astronômicos dela:

```xml
<ufoanalyzer_record version="200" clip_name="M20190712_091431_TLP_5" ...>
  <ua2_objects>
    <ua2_object fs="61" fe="70" sec="0.150150" av="5.019607" mag="-1.838501" class="J8_PPH" ... />
  </ua2_objects>
</ufoanalyzer_record>
```

O `bramon:index` lê esse arquivo durante a varredura da árvore e grava o resultado na
própria captura do índice:

| Endpoint | Campo | Origem |
|---|---|---|
| `/captures` | `class` | atributo `class` de `ufoanalyzer_record.ua2_object` (ex.: `J8_PPH`, `spo`, `__noise`) |
| `/captures` | `fs`, `fe`, `sec`, `av`, `mag` | mesmos atributos do elemento `ua2_object` |
| `/captures` | `has_analyze` | a captura tem (ou não) arquivo de análise |
| `/captures` | `analyzed` | o XML de análise trouxe pelo menos um dos dados acima |

Uma captura sem arquivo de análise — ou com um XML que não tem `ua2_object` —
continua com esses campos em `null`/`false`; o build do índice nunca quebra por
causa de um arquivo de análise corrompido.

> Como o payload da captura passou a carregar esses campos, um índice no formato
> anterior é ignorado (a versão do formato é validada na leitura). Rode
> `bramon:index` após atualizar.

### stations.json
```json
[
  {
    "id": "uuid-da-estacao",
    "operador": "uuid-do-operador",
    "source": "UFO",
    "name": "TLP1",
    "city": "Florianópolis",
    "state": "Santa Catarina",
    "country": "BR",
    "latitude": "-27.5904",
    "longitude": "-48.5925",
    "azimuth": "45",
    "elevation": "30",
    "_metadata": { "altitude": "12.0" },
    "fov": "90",
    "camera_model": "IMX291",
    "camera_lens": "Generic_F095_6mm",
    "camera_capture": "OBS_Studio",
    "active": true,
    "visible": true
  }
]
```

> O campo `operador` de `stations.json` é apenas o **fallback final** do de-para.
> A vinculação principal usa o nome da estação contra `operators.json`, porque
> em produção esse UUID não corresponde ao `id` de nenhum operador.

> `latitude`, `longitude` e `_metadata.altitude` também são apenas fallback: o
> índice sobrepõe com a posição medida nas capturas. `azimuth` e `elevation` são
> curados apenas e nenhuma captura os registra.

## 💾 Cache

- **TTL padrão:** 60 segundos (configurável via `BRAMON_CACHE_TTL`)
- **Invalidação automática:** Baseada em mtime dos arquivos JSON
- **Invalidação manual:** `POST /api/v2/public/cache/invalidate`

## 🧪 Testes

```bash
# Executar testes
php artisan test

# Testes com coverage
php artisan test --coverage
```

## 📝 Formatação de Código

```bash
# Verificar estilo
php artisan pint

# Corrigir automaticamente
php artisan pint --fix
```

## 📄 Documentação da API

- **Swagger UI:** Disponível em `/docs` (requer instalação de `darkaonline/l5-swagger`)
- **OpenAPI JSON:** Disponível em `/openapi.json`

## 🐳 Docker

### Build da imagem
```bash
docker build -t bramon-api .
```

### Executar container
```bash
docker run -d \
  --name bramon-api \
  -p 8080:8080 \
  -v /mnt/dados/BRAMON:/capturas:ro \
  -e BRAMON_BASE_DIR=/capturas \
  -e BRAMON_OPERATORS_FILE=/capturas/operators.json \
  -e BRAMON_STATIONS_FILE=/capturas/stations.json \
  bramon-api
```

## 🤝 Contribuição

1. Fork o projeto
2. Crie uma branch para sua feature (`git checkout -b feature/nova-feature`)
3. Commit suas mudanças (`git commit -am 'Adiciona nova feature'`)
4. Push para a branch (`git push origin feature/nova-feature`)
5. Abra um Pull Request

## 📄 Licença

Este projeto está licenciado sob a licença MIT - veja o arquivo [LICENSE](LICENSE) para detalhes.

---

**BRAMON API v2.0** - Brazilian Meteor Observation Network
