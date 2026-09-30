<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\FileSystemReader;
use App\Services\OperatorReader;
use Illuminate\Support\Facades\Log;

/**
 * Generates operators.json from the XML metadata of the captures tree.
 *
 * Memory notes: the previous version called getFiles() and array_filter()ed the
 * whole tree into a second array, so peak usage scaled with total file count
 * and the command died on the memory limit. This version streams files through
 * a generator and keeps only the small operator map in memory.
 */
class OperatorsScan extends Command
{
    protected $signature = 'bramon:operators-scan 
                            {--output= : Output file path (default: operators.json in base dir)}
                            {--base-dir= : Base directory for captures (default: config value)}
                            {--pretty : Pretty print JSON output}
                            {--force : Overwrite existing file without confirmation}';

    protected $description = 'Scan captures directory and generate operators.json from XML metadata';

    /**
     * Per-operator station membership sets for the current run.
     *
     * Using a lookup set avoids an O(n) in_array() scan for every file.
     *
     * @var array<string,array<string,bool>>
     */
    protected array $stationSeen = [];

    public function __construct(
        protected FileSystemReader $fileReader
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $baseDir = $this->option('base-dir') ?? config('bramon.base_dir');
        $outputFile = $this->option('output') ?? $baseDir . '/operators.json';
        $pretty = $this->option('pretty');
        $force = $this->option('force');

        $this->info("Scanning captures directory: {$baseDir}");

        // Always bypass the in-request cache: this scan must see the tree.
        $this->fileReader = new FileSystemReader($baseDir, 0);

        if (file_exists($outputFile) && !$force) {
            if (!$this->confirm("File {$outputFile} already exists. Overwrite?")) {
                $this->info('Operation cancelled.');
                return Command::SUCCESS;
            }
        }

        $this->info('Reading filesystem...');

        // Stream metadata files; never materialise the full file list.
        $operators = $this->extractOperatorsFromFiles($this->fileReader->getFilesGenerator(false));

        if (empty($operators)) {
            $this->warn('No operators found in XML metadata. Generating fallback operators from stations...');
            $operators = $this->generateFallbackOperators($this->fileReader->getFilesGenerator(false));
        }

        $json = $pretty
            ? json_encode($operators, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
            : json_encode($operators, JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            $jsonError = json_last_error_msg();
            $this->error("Failed to encode operators as JSON: {$jsonError}");

            // Debug output. Try to find invalid data.
            foreach ($operators as $index => $op) {
                $check = json_encode($op, JSON_UNESCAPED_UNICODE);
                if ($check === false) {
                    $errorDetail = json_last_error_msg();
                    $this->error("Error encoding operator at index {$index}: {$errorDetail}");
                    $this->error(print_r($op, true));
                    break;
                }
            }

            return Command::FAILURE;
        }

        if (@file_put_contents($outputFile, $json) === false) {
            $this->error("Failed to write {$outputFile}");

            return Command::FAILURE;
        }

        $this->info('Generated operators.json with ' . count($operators) . ' operators');
        $this->info("Output file: {$outputFile}");
        $this->info('Peak memory: ' . $this->humanBytes(memory_get_peak_usage(true)));

        $this->table(
            ['ID', 'Name', 'Email', 'Stations'],
            array_map(fn($op) => [
                $op['id'],
                $op['nome'],
                $op['email'],
                implode(', ', $op['estacoes'])
            ], $operators)
        );

        return Command::SUCCESS;
    }

    /**
     * Extract operators from XML metadata files.
     *
     * @param  iterable<array<string,mixed>>  $files
     * @return array<int,array<string,mixed>>
     */
    protected function extractOperatorsFromFiles(iterable $files): array
    {
        $operatorsMap = [];
        $xmlCount = 0;

        foreach ($files as $file) {
            // Skip non-metadata files as they stream past, without buffering.
            if (($file['type'] ?? null) !== 'metadata' || ($file['extension'] ?? null) !== 'xml') {
                continue;
            }

            $xmlCount++;
            $operatorInfo = $this->extractOperatorFromXml($file['path']);

            if ($operatorInfo === null) {
                continue;
            }

            $key = $operatorInfo['email'] ?? $operatorInfo['nome'];

            if (!isset($operatorsMap[$key])) {
                $operatorsMap[$key] = [
                    'id' => $operatorInfo['id'] ?? $this->generateOperatorId($operatorInfo),
                    'nome' => $operatorInfo['nome'] ?? 'Operador Desconhecido',
                    'email' => $operatorInfo['email'] ?? '',
                    'visivel' => true,
                    'ativo' => true,
                    'estacoes' => [],
                ];

                $this->stationSeen[$key] = [];
            }

            $station = (string) $file['station'];

            if (!isset($this->stationSeen[$key][$station])) {
                $this->stationSeen[$key][$station] = true;
                $operatorsMap[$key]['estacoes'][] = $station;
            }
        }

        $this->stationSeen = [];

        $this->info("Processed {$xmlCount} metadata files.");

        foreach ($operatorsMap as &$op) {
            sort($op['estacoes']);
        }
        unset($op);

        return array_values($operatorsMap);
    }

    /**
     * Extract operator info from XML file.
     */
    protected function extractOperatorFromXml(string $xmlPath): ?array
    {
        try {
            $content = file_get_contents($xmlPath);
            if (!$content) {
                return null;
            }

            // Find ufocapture_record tag
            if (!preg_match('/<ufocapture_record[^>]*>/', $content, $matches)) {
                return null;
            }

            $tagContent = $matches[0];
            $attributes = [
                'observer' => 'observer="([^"]*)"',
                'lid' => 'lid="([^"]*)"',
            ];

            $info = [];
            foreach ($attributes as $key => $pattern) {
                if (preg_match('/' . $pattern . '/', $tagContent, $match)) {
                    $info[$key] = $match[1];
                }
            }

            if (empty($info['observer']) && empty($info['lid'])) {
                return null;
            }

            $nome = $info['observer'] ?? $info['lid'] ?? 'Operador Desconhecido';
            // Normalize the name to UTF-8, remove invalid characters
            $nome = mb_convert_encoding($nome, 'UTF-8', 'UTF-8');
            $nome = preg_replace('/[\x00-\x1F\x7F]/u', '', $nome);
            $nome = trim($nome);
            // No capture carries an address, so it is derived from the name —
            // the same rule the indexer uses, to keep both in sync.
            $email = OperatorReader::emailFromName($nome);

            return [
                'id' => $info['lid'] ?? $this->generateOperatorId(['nome' => $nome]),
                'nome' => $nome,
                'email' => $email,
            ];

        } catch (\Throwable $e) {
            Log::warning("Failed to parse XML: {$xmlPath} - " . $e->getMessage());
            return null;
        }
    }

    /**
     * Generate fallback operators from stations.
     *
     * @param  iterable<array<string,mixed>>  $files
     * @return array<int,array<string,mixed>>
     */
    protected function generateFallbackOperators(iterable $files): array
    {
        $stations = [];

        foreach ($files as $file) {
            $station = (string) $file['station'];
            $stations[$station] = true;
        }

        $stations = array_keys($stations);
        sort($stations);

        $operators = [];

        foreach ($stations as $station) {
            $operators[] = [
                'id' => $this->generateOperatorId(['nome' => "Operador {$station}"]),
                'nome' => "Operador {$station}",
                'email' => strtolower($station) . '@bramonmeteor.org',
                'visivel' => true,
                'ativo' => true,
                'estacoes' => [$station],
            ];
        }

        return $operators;
    }

    /**
     * Generate a unique operator ID.
     */
    protected function generateOperatorId(array $operator): string
    {
        $key = $operator['email'] ?? $operator['nome'] ?? '';
        return substr(md5($key), 0, 32);
    }

    protected function humanBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;

        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return number_format($bytes, $i === 0 ? 0 : 1) . ' ' . $units[$i];
    }
}
