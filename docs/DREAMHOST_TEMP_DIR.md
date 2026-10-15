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
