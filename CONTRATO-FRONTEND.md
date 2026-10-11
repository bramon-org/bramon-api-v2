# Contrato de Integração Front-end — BRAMON API v2

> Prompt pronto para colar no Lovable. Foi escrito contra o comportamento **real** da API
> (verificado em `http://localhost:8080`, com 33.442 capturas e 10 estações).
> Os pontos marcados como ⚠️ são divergências entre a documentação OpenAPI e o que a API
> realmente devolve. O código do front-end deve seguir **a API real**, não o OpenAPI.

---

## 🎯 Objetivo

Refatorar a integração com a BRAMON API para que o app consuma os endpoints abaixo de forma
correta. Hoje o front-end provavelmente assume formatos de paginação, nomes de campo e
datas que **não existem** nessa API (detalhados em "Armadilhas").

**Não altere o design visual existente.** O escopo é a camada de dados: `src/lib/api.ts`,
os hooks em `src/hooks/`, os tipos e os componentes que consomem esses hooks.

**Stack obrigatória:** React + TypeScript + Vite + Tailwind + shadcn/ui + TanStack Query
(`@tanstack/react-query`). Se o projeto ainda não usa TanStack Query, adote-o — não
implemente fetching com `useEffect` manual.

---

## 🔌 Conexão

```ts
// src/lib/api.ts
export const API_BASE_URL = import.meta.env.VITE_API_BASE_URL ?? 'http://localhost:8080/api/v2/public';
```

Variáveis de ambiente (crie `.env` e `.env.example`):

```
VITE_API_BASE_URL=http://localhost:8080/api/v2/public
```

- **Não existe autenticação.** Nenhum header `Authorization`, nenhuma API key, nenhum login.
  Não implemente fluxo de token, refresh token ou interceptor de auth.
- **CORS já está liberado** com `Access-Control-Allow-Origin: *`. Chame a API direto do
  navegador; não crie proxy/dev-server rewrite.
- Build de produção precisa de `.env.production` apontando para o host real da API.

---

## 📡 Endpoints

Todos relativos a `API_BASE_URL`. Todos respondem JSON exceto o marked como binário.

| # | Método | Path | Tipo |
|---|--------|------|------|
| 1 | GET | `/health` | JSON |
| 2 | GET | `/stats` | JSON |
| 3 | POST | `/cache/invalidate` | JSON |
| 4 | GET | `/captures` | JSON paginado |
| 5 | GET | `/captures/search` | JSON **não paginado** ⚠️ |
| 6 | GET | `/captures/{captureId}` | JSON |
| 7 | GET | `/captures/{captureId}/files/{fileType}` | **Binário** (imagem/XML) |
| 8 | GET | `/stations` | JSON paginado |
| 9 | GET | `/stations/{stationName}` | JSON |
| 10 | GET | `/stations/{stationName}/captures` | JSON paginado |
| 11 | GET | `/stations/{stationName}/stats` | JSON |
| 12 | GET | `/operators` | JSON paginado |
| 13 | GET | `/operators/{operatorId}` | JSON |

⚠️ **Endpoints 9, 10 e 11 recebem o CÓDIGO da estação** (`TLP1`, `STA07`), **não** o `id`
(longa UUID) que aparece no objeto. Use `station.name` na URL, nunca `station.id`.

---

## 🧩 Tipos TypeScript

Crie `src/types/api.ts`. Copie exatamente:

```ts
export interface CaptureFile {
  /** Caminho RELATIVO ao diretório de dados. NÃO é uma URL. Não use como src. */
  url: string;
  /** ⚠️ pode ser 'unknown', fora do enum documentado no OpenAPI. */
  type: 'metadata' | 'preview' | 'thumbnail' | 'analyze' | 'unknown';
  filename: string;
  extension: string;
  size: number | null;
  /** ISO 8601 com offset, ex: "2025-08-02T03:49:08+00:00" */
  modified_at: string | null;
}

export interface Capture {
  /** md5 truncado de station+date+time. Use como chave de rota. */
  id: string;
  /** Código da estação, ex: "TLP1". */
  station: string;
  /** ⚠️ "YYYY-MM-DD HH:mm:ss" — ESPAÇO, sem timezone. Não é ISO 8601. Ver formatDate(). */
  captured_at: string;
  /** "YYYYMMDD", ex: "20250802" */
  capture_date: string;
  /** "HHMMSS", ex: "034905" */
  capture_time: string;
  /** Observador lido do XML associado à sequência da captura. */
  observer: string | null;
  files: CaptureFile[];
  file_count: number;
  has_metadata: boolean;
  has_preview: boolean;
  has_thumbnail: boolean;
  /** ⚠️ Sempre null na API atual. Ver " Campos mortos". Não construa UI sobre eles. */
  class: string | null;
  fs: number | null;
  fe: number | null;
  sec: number | null;
  av: number | null;
  mag: number | null;
  /** ⚠️ Sempre false, mesmo quando existe arquivo 'analyze'. */
  analyzed: boolean;
}

export interface Operator {
  /** Código curto, ex: "TLP". NÃO é uuid. */
  id: string;
  name: string;
  email: string;
  role: string;
  visible: boolean;
  active: boolean;
  station_count: number;
  /** Códigos das estações, ex: ["TLP1","TLP2"] */
  stations: string[];
  /**
   * Posição medida na PRIMEIRA estação de `stations` — a ordem do array, não
   * a ordem alfabética. Nenhuma captura registra onde o operador mora, então a
   * coordenada é a da estação. `null` quando essa estação ainda não fixou
   * posição. Mesmos tipos e semântica dos campos de `Station`.
   */
  latitude: number | null;
  longitude: number | null;
  /** Altitude em metros, da mesma captura da latitude/longitude. */
  altitude: number | null;
}

export interface Station {
  /** UUID do manifesto. NÃO usar em URL — use `name`. */
  id: string;
  /** Código da estação. Este é o valor usado nas rotas e no filtro `station`. */
  name: string;
  /** ⚠️ O nome do campo é em PORTUGUÊS: `operador`, não `operator`. */
  operador: Operator | null;
  city: string;
  state: string;
  country: string;
  latitude: number;
  longitude: number;
  /** Altitude em metros. Nova: lida da captura mais recente com posição válida. */
  altitude: number | null;
  /**
   * ⚠️ Curado em stations.json — NENHUMA captura registra azimute, então fica
   * sempre 0 no dataset atual. Não treat como medição.
   */
  azimuth: number;
  /**
   * ⚠️ Curado em stations.json — NENHUMA captura registra elevação, então fica
   * sempre 0 no dataset atual. Para a altura do ponto de observação use
   * `altitude`, não `elevation`.
   */
  elevation: number;
  fov: number;
  camera_model: string;
  camera_lens: string;
  camera_capture: string;
  active: boolean;
  visible: boolean;
  capture_count: number;
  /** "YYYY-MM-DD HH:mm:ss" ou null */
  first_capture: string | null;
  last_capture: string | null;
}

export interface Health {
  status: 'healthy' | 'degraded';
  timestamp: string;
  base_dir: string;
  base_dir_exists: boolean;
  index: {
    built: boolean;
    generated_at: string | null;
    total_captures: number;
    total_stations: number;
    disk_usage: number;
  };
  memory: { current: number; peak: number; limit: number | null };
  /** Só existe quando status === 'degraded' */
  hint?: string;
}

export interface StationStats {
  station: string;
  total_captures: number;
  first_capture: string | null;
  last_capture: string | null;
  /** ⚠️ Formato correto: objeto indexado por "AAAAMM", ex: { "201808": 8 } */
  captures_by_month: Record<string, number>;
}

export interface Stats {
  total_captures: number;
  total_stations: number;
  total_operators: number;
  total_files: number;
  date_range: { earliest: string; latest: string };
  /** ⚠️⚠️ BUG NA API: sempre devolve [] (array vazio). Não usar. */
  captures_by_station: unknown[];
  /** ⚠️⚠️ BUG NA API: array posicional de NÚMEROS, com os rótulos de mês PERDIDOS.
   *  Ex: [8, 114, 1, ...] onde 8 = capturas em 201808, 114 = 201809.
   *  O índice i corresponde ao i-ésimo mês de `date_range`, sem rótulo.
   *  NÃO tente exibir como { mês: contagem }. Use /stations/{name}/stats. */
  captures_by_month: number[];
  last_updated: string;
}

export interface SearchResponse {
  query: string;
  total: number;
  results: Capture[];
}

export interface ApiError {
  detail: string;
  errors?: Record<string, string[]>;
}
```

### Envelope paginado (⚠️ CHAVE DO BUG)

A paginação é **CHATA** — os contadores são irmãos do array `data`, **não** aninhados em
`meta`/`links` como no default do Laravel e em quase toda API REST:

```ts
export interface Paginated<T> {
  data: T[];
  total: number;
  page: number;
  per_page: number;
  total_pages: number;
  has_next: boolean;
  has_prev: boolean;
}

// Correto:
const res = await fetch(url);
const page: Paginated<Capture> = await res.json();
page.data;      // itens
page.total;     // total
page.has_next;  // booleano pronto para o botão "próxima"

// ERRADO — nunca faça isso:
page.meta.total;
page.links.next;
page.items;
```

---

## 📥 Query params

### Listagens paginadas (`/captures`, `/stations`, `/operators`, `/stations/{name}/captures`)

| Param | Tipo | Default | Regras |
|-------|------|---------|--------|
| `page` | int ≥ 1 | `1` | Base 1. Fora do intervalo devolve `data: []`, não erro. |
| `per_page` | int 1–100 | `15` | ⚠️ **Acima de 100 retorna 422**, não é clamped. Limite no input. |
| `active_only` | bool | `false` | Só em `/stations` e `/operators`. |
| `use_cache` | bool | `true` | Aceito e ignorado. Pode omitir. |

`per_page=0` e `per_page=500` retornam **422**. Valide antes de enviar.

### Filtros de captura (`/captures` e `/stations/{name}/captures`)

| Param | Formato | Notas |
|-------|---------|-------|
| `station` | string | ⚠️ **Case-sensitive, match exato.** `station=tlp1` → `total: 0`. Use o `name` exato da estação. |
| `date_from` | `YYYY-MM-DD` | Inclusivo |
| `date_to` | `YYYY-MM-DD` | Inclusivo |
| `has_metadata` | bool | |
| `has_preview` | bool | |

Formato de data errado (`01/08/2025`) → **422** com
`{"errors":{"date_from":["Date from must be in YYYY-MM-DD format."]}}`.

### Busca (`/captures/search`)

| Param | Regras |
|-------|--------|
| `q` | **Obrigatório.** string 1–200. Ausente → 422. |
| `limit` | int 1–200, default `50`. |

⚠️ A busca **não é paginada**. Retorna `{ query, total, results }` — sem `page`, sem
`has_next`. `total` é o total de matches, `results.length` é ≤ `limit`. **Não monte
controles de paginação na busca.** Para ver mais resultados, aumente `limit`.

---

## 🖼️ Arquivos binários

Para exibir preview/thumbnail, **não** use `files[].url` (é caminho relativo, não URL).
Monte a URL do endpoint:

```ts
export type FetchableFileType = 'metadata' | 'preview' | 'thumbnail' | 'analyze';

export const captureFileUrl = (captureId: string, type: FetchableFileType) =>
  `${API_BASE_URL}/captures/${captureId}/files/${type}`;

export const pickFile = (c: Capture, type: FetchableFileType): CaptureFile | undefined =>
  c.files.find((f) => f.type === type);
```

```tsx
<img src={captureFileUrl(capture.id, 'thumbnail')} alt={`Thumbnail ${capture.id}`} loading="lazy" />
```

- `fileType` só aceita `metadata | preview | thumbnail | analyze`. Arquivos com
  `type: 'unknown'` (avi, csv, txt) **não são servidos** → 404. Filtre-os na UI.
- Um mesmo capture pode ter **vários** arquivos `thumbnail` (ex: `.jpg` e `.bmp`).
  `pickFile` devolve o primeiro — considere todos se a UI precisar.
- Resposta 200 é binária (`Content-Type` segue o arquivo). Erros são JSON.

---

## 🚨 Tratamento de erro

Formato de erro da API é `{ detail: string }` — **singular e sem `message`**:

```ts
export class ApiRequestError extends Error {
  constructor(readonly status: number, readonly body: ApiError) {
    super(body.detail);
    this.name = 'ApiRequestError';
  }
  /** Mensagens por campo, vindas de 422 de validação. */
  get fieldErrors() { return this.body.errors ?? {}; }
}
```

Status a tratar:

| Status | Causa | UX |
|--------|-------|-----|
| `422` | Query param inválido | Mostrar `detail` + erros por campo |
| `404` | Captura/estação/operador inexistente | Estado "não encontrado", não tela de erro |
| `503` | Índice não construído (`{detail: "Índice de capturas não construído..."}`) | ⚠️ Ver abaixo |
| `5xx` | Falha do servidor | Retry com backoff |

**503 é um caso especial:** a API inteira de listagem depende do índice em disco. Quando
ocorre, **todas** as listagens caem de uma vez. Faça:

1. `GET /health` para distinguir. Se `status === 'degraded'`, mostre um aviso único e
   **global** (banner) com o `hint` da API (ex.: "Execute: php artisan bramon:index").
2. Desabilite paginação e filtros (não adianta repetir request que vai falhar).
3. **Não** faça retry automático agressivo em 503 — só se recupera com rebuild do índice
   do lado do servidor.

### `POST /cache/invalidate`

Devolve `{ message: string }` e **apaga o índice em disco**, derrubando todas as listagens
até o backend rodar `php artisan bramon:index`.

⚠️ Esta rota **não exige autenticação** — qualquer cliente pode chamá-la. **Não a exponha
na UI.** Remova qualquer botão "limpar cache" que exista, ou deixe-o atrás de uma flag
`VITE_ENABLE_DANGEROUS_ADMIN === 'true'` desligada por default. É um botão de
denial-of-service para a própria API.

---

## 🪤 Armadilhas (resumo dos bugs de integração)

| # | Suposição comum | Realidade |
|---|-----------------|-----------|
| 1 | `response.meta.total` | `response.total` — envelope é **chato** |
| 2 | `response.data.items` | `response.data` já é o array |
| 3 | `error.response.data.message` | É `error.body.detail` |
| 4 | `station.operator` | É `station.operador` (português) |
| 5 | `new Date(captured_at)` | `"2025-08-02 03:49:05"` — espaço, sem TZ. Ver helper abaixo. |
| 6 | `/stations/{id}` com UUID | Usa o **código** (`TLP1`), não o `id` |
| 7 | Busca tem paginação | Não tem — só `limit` (máx 200) |
| 8 | `per_page=1000` funciona | 422. Máximo é 100 |
| 9 | `files[].url` é URL | É caminho relativo. Use o endpoint de arquivo |
| 10 | Filtro `station` é case-insensitive | Case-sensitive. `tlp1` → 0 resultados |
| 11 | `/stats.captures_by_month` é objeto | É **array de números sem rótulo** ⚠️ bug do backend |
| 12 | `/stats.captures_by_station` tem dados | Sempre `[]` ⚠️ bug do backend |
| 13 | `capture.analyzed` indica análise | Sempre `false` ⚠️ campos mortos |
| 14 | Busca aceita `q` vazio | 422 — `q` é obrigatório |

### Helper de data obrigatório

```ts
/**
 * A API devolve "2025-08-02 03:49:05": espaço como separador e sem timezone.
 * new Date() interpreta isso de forma inconsistente entre navegadores.
 * Converte para um ISO 8601 com timezone assumido em UTC.
 */
export const parseCaptureDate = (value: string): Date =>
  new Date(value.includes('T') ? value : value.replace(' ', 'T') + 'Z');

export const formatCaptureDate = (value: string): string =>
  parseCaptureDate(value).toLocaleString('pt-BR');
```

Para `capture_date` (`"20250802"`) e `capture_time` (`"034905"`), recorte a string —
não passe para `new Date()`.

---

## 💀 Campos mortos — não construir UI sobre eles

`Capture.class`, `.fs`, `.fe`, `.sec`, `.av`, `.mag` e `.analyzed` **sempre chegam
`null`/`false`**, mesmo em capturas que possuem arquivo `analyze` com todos esses dados
no XML. O backend ainda não extrai o XML de análise.

Verificado em 100+ capturas: `analyzed: false` em 100% dos casos; os 7 campos astronômicos
`null` em 100% dos casos — apesar de 100% dessas capturas terem o arquivo `analyze` no disco.

**Instrução:** remova da UI qualquer card/painel de "análise astronômica", "magnitude",
"trajetória" ou "classificação do meteoro" que dependa desses campos. Se o design já tem
esse bloco, mantenha-o mas mostre um estado vazio honesto ("Análise indisponível
na versão atual da API") em vez de zeros falsos.

Para distribuição mensal correta, use `GET /stations/{name}/stats`, cujo
`captures_by_month` **é um objeto `{ "201808": 8, "201809": 114 }`** e funciona.

### Também curados, não medidos: `Station.azimuth` e `Station.elevation`

Nenhum dos 32.903 XMLs de metadados tem atributo de azimute ou elevação — a tag
`<ufocapture_record>` carrega apenas `lat`, `lng` e `alt`. Os dois campos vêm
exclusivamente de `stations.json`, onde estão `0` para todas as 10 estações. Não
construa bússola, arqueamento de ponto ou perfil de câmera sobre eles.

A altura do ponto de observação está em `Station.altitude`, esse sim lido da
captura (`alt`), em metros.

---

## 🏗️ Estrutura esperada

```
src/
├── lib/
│   ├── api.ts            # fetch wrapper, ApiRequestError, captureFileUrl, formatCaptureDate
│   └── queryKeys.ts      # factories de chaves
├── types/api.ts          # interfaces acima
└── hooks/
    ├── useHealth.ts
    ├── useStats.ts
    ├── useCaptures.ts
    ├── useCaptureSearch.ts
    ├── useCapture.ts
    ├── useStations.ts
    ├── useStation.ts
    ├── useStationCaptures.ts
    ├── useStationStats.ts
    ├── useOperators.ts
    └── useOperator.ts
```

### Padrão obrigatório: um client tipado + hooks finos

```ts
// src/lib/api.ts
const request = async <T>(path: string, init?: RequestInit): Promise<T> => {
  const res = await fetch(`${API_BASE_URL}${path}`, {
    ...init,
    headers: { Accept: 'application/json', ...init?.headers },
  });

  if (!res.ok) {
    let body: ApiError = { detail: `Erro ${res.status}` };
    try { body = await res.json(); } catch { /* resposta não-JSON */ }
    throw new ApiRequestError(res.status, body);
  }
  return res.json() as Promise<T>;
};

export const api = {
  health:        () => request<Health>('/health'),
  stats:         () => request<Stats>('/stats'),
  captures:      (p: CaptureQuery) =>
    request<Paginated<Capture>>(`/captures?${toQueryString(p)}`),
  captureSearch: (q: string, limit = 50) =>
    request<SearchResponse>(`/captures/search?${toQueryString({ q, limit })}`),
  capture:       (id: string) => request<Capture>(`/captures/${id}`),
  stations:      (p: PageQuery) =>
    request<Paginated<Station>>(`/stations?${toQueryString(p)}`),
  station:       (name: string) => request<Station>(`/stations/${name}`),
  stationCaptures: (name: string, p: PageQuery) =>
    request<Paginated<Capture>>(`/stations/${name}/captures?${toQueryString(p)}`),
  stationStats:  (name: string) => request<StationStats>(`/stations/${name}/stats`),
  operators:     (p: PageQuery) =>
    request<Paginated<Operator>>(`/operators?${toQueryString(p)}`),
  operator:      (id: string) => request<Operator>(`/operators/${id}`),
};
```

`toQueryString` **deve** omitir `undefined`/`null`/string vazia, senão você manda
`?station=` e a API interpreta como filtro presente.

```ts
// src/hooks/useCaptures.ts
export const useCaptures = (params: CaptureQuery = {}) =>
  useQuery({
    queryKey: ['captures', params],
    queryFn: () => api.captures(params),
    placeholderData: keepPreviousData, // evita flash branco ao paginar
  });
```

**Regras de query:**
- `queryKey` deve conter **todos** os params (inclusive `page`), senão páginas
  diferentes colidem no cache.
- Use `keepPreviousData` em toda listagem paginada.
- `retry`: 1 para erro de rede/5xx; **0** para 404 e 503.
- Respeite `enabled` quando o id/nome depender de rota (ex.: `enabled: !!stationName`).

---

## ✅ Critérios de aceitação

A correção está pronta quando **todos** passam:

1. `/captures` renderiza a primeira página lendo `data`, `total`, `total_pages`,
   `has_next` do **envelope chato**. Nada acessa `meta`.
2. Botão "próxima página" usa `has_next` e incrementa `page`. Botão "anterior" usa
   `has_prev`. Trocar de página **não** mostra tela de loading branca.
3. O seletor de `per_page` nunca oferece valor acima de 100, e nenhum request é disparado
   com `per_page` inválido.
4. Filtro de estação envia o código **exato** (ex.: `TLP1`) e retorna resultados.
   Busca por `tlp1` minúsculo não é usada em lugar nenhum.
5. Datas exibidas passam por `parseCaptureDate`/`formatCaptureDate`. Nenhum
   `new Date(captured_at)` cru no código.
6. Detalhe de estação navega por `/stations/TLP1` usando `station.name`.
7. O nome do operador na estação é lido de `station.operador`, com fallback para `null`
   (estação pode não ter operador). Nenhum acesso a `station.operator`.
8. Preview e thumbnail carregam via `captureFileUrl(id, type)`; nenhum `<img src>`
   aponta para `files[].url` cru. `unknown` é filtrado.
9. Busca não mostra controle de paginação; usa `limit` (≤ 200).
10. Erro de listagem mostra `detail`. 404 mostra estado vazio. 503 mostra banner com o
    `hint` e desabilita filtros/paginação.
11. `POST /cache/invalidate` **não** é chamado por nenhuma ação da UI.
12. Nenhum componente renderiza `class`, `fs`, `fe`, `sec`, `av`, `mag` ou `analyzed`
    assumindo que terá valor.
13. Nenhum gráfico usa `/stats.captures_by_month` como se tivesse rótulos de mês.
14. `npx tsc --noEmit` e `npm run build` passam sem erro. Zero `any` nos types da API.

---

## 🧪 Como validar

```bash
# 1. A API responde?
curl http://localhost:8080/api/v2/public/health

# 2. Confirme o envelope chato (total está no topo, não em meta)
curl "http://localhost:8080/api/v2/public/captures?per_page=2" | jq 'keys'
# esperado: ["data","has_next","has_prev","page","per_page","total","total_pages"]

# 3. Confirme que o filtro de estação é case-sensitive
curl "http://localhost:8080/api/v2/public/captures?station=TLP1"  | jq .total   # > 0
curl "http://localhost:8080/api/v2/public/captures?station=tlp1"  | jq .total   # 0

# 4. Confirme os bugs de /stats (não confie neles)
curl http://localhost:8080/api/v2/public/stats | jq '.captures_by_station'      # []
curl http://localhost:8080/api/v2/public/stats | jq '.captures_by_month[0:3]'  # [8,114,1]

# 5. Confirme que o formato correto está em /stations/{name}/stats
curl http://localhost:8080/api/v2/public/stations/TLP1/stats | jq '.captures_by_month[0:3]'
# esperado: { "201808": 8, ... }

# 6. Confirme que /health devolve 503 quando o índice não existe (após cache/invalidate)
curl -X POST http://localhost:8080/api/v2/public/cache/invalidate
curl -o /dev/null -w "%{http_code}\n" http://localhost:8080/api/v2/public/captures  # 503
# ⚠️ Depois rode: docker exec bramon-api php artisan bramon:index
```

Ao terminar, **rode `docker exec bramon-api php artisan bramon:index`** caso tenha executado
o passo 6, para restaurar o ambiente.

---

## 📎 Referência do backend (para contexto)

- **Repositório:** `bramon-api-v2` (Laravel 11, PHP 8.4)
- **Spec OpenAPI:** atributos PHP em `app/Docs/` (OpenAPI gerado em `/openapi.json`).
  A spec documenta `files[].type` sem o valor `unknown` e omite os campos
  `class/fs/fe/sec/av/mag/analyzed` da captura — divergências já contornadas acima.
- **Comportamento depende de índice em disco** (`php artisan bramon:index`), o que explica
  o 503 e por que listagens são rápidas.
- **Fonte dos dados:** `operators.json` e `stations.json` + árvore de capturas em
  `<BASE_DIR>/<ESTACAO>/<ano>/<AAAAMM>/<AAAAMMMDD>/M<AAAAMMMDD>_<HHMMSS>_<ESTACAO>_<TIPO>.<ext>`.
- **Geolocalização:** `Station.latitude/longitude/altitude` e os campos de
  `Operator` vêm do XML (`lat`/`lng`/`alt`) da captura mais recente da estação que
  **fixou posição** — uma captura com `0/0` é descartada e a anterior válida é usada.
  O índice precisa ser reconstruído (`php artisan bramon:index`) para isso mudar.

Se precisar mudar algo no backend (corrigir `captures_by_station`/`captures_by_month` de
`/stats`, popular os campos astronômicos, remover `cache/invalidate` do escopo público),
**não faça no front-end** — reporte e trate com um mock temporário se precisar destravar.