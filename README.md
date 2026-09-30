# BRAMON API v2.0 (Laravel)

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
  "stations": ["TLP1", "TLP10", "TLP2"]
}
```

O de-para é feito **a partir do nome da estação**, invertendo as mesmas regras de
`operators.json`, de modo que `/stations` e `/operators` nunca discordam sobre quem
possui cada estação. Para cada estação, nesta ordem:

1. a lista `estacoes` do operador contém o nome da estação
2. o nome da estação começa com o prefixo `estacao` do operador
3. o campo `operador` da própria `stations.json` bate com o `id` de um operador

Um operador com lista `estacoes` explícita nunca "vaza" pelo seu prefixo. Se
`operators.json` estiver ausente ou vazio, um operador é sintetizado por estação —
o mesmo fallback usado por `/operators`. Quando nenhuma regra casa, `operador`
retorna `null`.

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