# Configuração de Diretório Temporário para DreamHost

## Problema

O comando `bramon:index` falha na DreamHost porque:

1. **ExternalSort usa muita memória** (5000 registros por chunk)
2. **Duas passagens completas pelos arquivos** (lento para milhares de arquivos)
3. **Arquivos temporários em `sys_get_temp_dir()`** (pode ter restrições na DreamHost)
4. **Limite de tempo de execução** (30-60s em hospedagem compartilhada)

## Solução

Adicionada a configuração `BRAMON_TEMP_DIR` para permitir que os arquivos temporários do ExternalSort sejam gravados em um diretório configurável.

## Como configurar na DreamHost

### 1. Crie um diretório temporário no seu home

```bash
mkdir -p ~/tmp/bramon
chmod 700 ~/tmp/bramon
```

### 2. Configure a variável de ambiente

No seu arquivo `.env` (ou no painel da DreamHost), adicione:

```env
BRAMON_TEMP_DIR=/home/bramon_api/tmp/bramon
```

### 3. Reduza o chunk size (opcional, mas recomendado)

Para reduzir o uso de memória, você pode diminuir o chunk size:

```env
BRAMON_INDEX_CHUNK_SIZE=1000
```

O padrão é 5000, mas em hospedagem compartilhada com memória limitada, um valor menor pode ajudar.

### 4. Execute o comando com limite de memória aumentado

```bash
php -d memory_limit=512M artisan bramon:index --force
```

Ou use o wrapper `artisan-cli`:

```bash
./artisan-cli --memory 512M bramon:index --force
```

## Verificação

Após configurar, execute o comando e verifique se:

1. Os arquivos temporários estão sendo criados no diretório configurado
2. O comando completa sem erros de memória
3. O índice é gerado com sucesso

## Arquivos modificados

- `config/bramon.php` - Adicionada configuração `temp_dir`
- `app/Services/ExternalSort.php` - Construtor aceita `$tempDir` opcional
- `app/Services/CaptureIndex.php` - Método `rebuild()` aceita `$tempDir` opcional
- `app/Console/Commands/IndexBuild.php` - Passa `config('bramon.temp_dir')` para o rebuild
- `.env.example` - Documentada a nova variável `BRAMON_TEMP_DIR`

## Como funciona

O `ExternalSort` agora usa o diretório configurado em `BRAMON_TEMP_DIR` (ou `sys_get_temp_dir()` como fallback) para criar os arquivos temporários de sort. Isso permite que você use um diretório dentro do seu home na DreamHost, que geralmente tem menos restrições que o `/tmp` do sistema.

## Bases muito grandes: build com retomada (checkpoints) e filtro por data

Para árvores com milhões de arquivos, o `bramon:index` oferece duas opções que podem ser combinadas:

### 1. `--resume`: retoma de onde parou

```bash
php artisan bramon:index --force --resume
```

A varredura avança estação por estação (em ordem alfabética). Após cada estação concluída, os blocos ordenados já processados ficam gravados no diretório de staging (`<index_dir>.building`) junto com um `checkpoint.json` listando as estações prontas. Se o processo cair (timeout, OOM, queda de SSH), basta rodar o mesmo comando de novo: as estações do checkpoint são puladas e só o restante é varrido.

- O manifesto final registra `"resumed": true` quando blocos de um checkpoint foram aproveitados.
- `--fresh` (combinado com `--resume`) descarta o checkpoint e recomeça do zero.
- Um checkpoint de outra base ou de outro filtro de data é descartado automaticamente (com aviso), nunca reaproveitado em silêncio.
- `--flush` também remove checkpoints órfãos.

### 2. `--since-days=X` ou `--since=AAAA-MM-DD`: indexa só o período recente

```bash
php artisan bramon:index --force --since-days=30
php artisan bramon:index --force --since=2026-01-01
```

Só capturas com data (do nome do arquivo) maior ou igual ao corte entram no índice. Arquivos antigos são descartados pelo nome antes de qualquer `stat()` ou leitura de XML, então o ganho é proporcional ao volume ignorado. O filtro aplicado fica registrado no manifesto em `filter` (`{"since": "2026-09-15", "since_days": 30}`), deixando claro que os totais cobrem uma janela parcial.

### Estratégia sugerida em hospedagem compartilhada

```bash
php -d memory_limit=512M artisan bramon:index --force --resume --since-days=90
```

1. `--since-days` reduz o trabalho à janela que importa;
2. `--resume` permite repetir o comando até concluir, uma estação por vez, sem perder o progresso;
3. `BRAMON_INDEX_CHUNK_SIZE=1000` (no `.env`) mantém o pico de memória baixo — o uso agora é limitado pelo chunk, pois os fatos de metadata viajam junto da captura nos blocos ordenados em vez de acumularem em memória.
