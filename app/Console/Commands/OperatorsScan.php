<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\CaptureIndex;
use App\Services\DataProcessor;
use App\Services\FileSystemReader;
use App\Services\OperatorReader;
use Illuminate\Support\Facades\Log;

/**
 * Generates operators.json from the capture index.
 *
 * Reads station aggregates and metadata from the prebuilt CaptureIndex
 * (the same data source as bramon:index), so operators are derived from
 * actual captures rather than scanning the filesystem directly.
 */
class OperatorsScan extends Command
{
    protected $signature = 'bramon:operators-scan 
                            {--output= : Output file path (default: operators.json in base dir)}
                            {--index-dir= : Index directory (default: config value)}
                            {--pretty : Pretty print JSON output}
                            {--force : Overwrite existing file without confirmation}';

    protected $description = 'Generate operators.json from the capture index';

    public function __construct(
        protected DataProcessor $dataProcessor
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $baseDir = config('bramon.base_dir');
        $indexDir = $this->option('index-dir') ?? config('bramon.index_dir');
        $outputFile = $this->option('output') ?? $baseDir . '/operators.json';
        $pretty = $this->option('pretty');
        $force = $this->option('force');

        $this->info("Reading from index: {$indexDir}");

        $index = new CaptureIndex($indexDir);

        if (!$index->exists()) {
            $this->error('Capture index not found. Run php artisan bramon:index first.');
            return Command::FAILURE;
        }

        if (file_exists($outputFile) && !$force) {
            if (!$this->confirm("File {$outputFile} already exists. Overwrite?")) {
                $this->info('Operation cancelled.');
                return Command::SUCCESS;
            }
        }

        $this->info('Reading operators from index...');

        // Get stations from the index (same as bramon:index does)
        $stations = $this->dataProcessor->stationsFromIndex($index);

        // Build operators from the station data
        $operators = $this->buildOperatorsFromStations($stations, $index);

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
     * Build operators from station data (same as bramon:index does).
     *
    * @param  array<int,array<string,mixed>>  $stations
     * @return array<int,array<string,mixed>>
     */
    protected function buildOperatorsFromStations(array $stations, CaptureIndex $index): array
    {
        $operatorsMap = [];

        foreach ($stations as $station) {
            $operator = $station['operador'] ?? null;

            if ($operator === null) {
                continue;
            }

            $stationName = (string) ($station['name'] ?? '');
            $operatorId = $this->stationOperatorId($stationName);

            if (!isset($operatorsMap[$operatorId])) {
                $operatorsMap[$operatorId] = [
                    'id' => $operatorId,
                    'nome' => $operator['name'] ?? $operator['nome'] ?? '',
                    'email' => $operator['email'] ?? '',
                    'visivel' => $operator['visible'] ?? $operator['visivel'] ?? true,
                    'ativo' => $operator['active'] ?? $operator['ativo'] ?? true,
                    'estacoes' => [],
                ];
            }

            if (!in_array($stationName, $operatorsMap[$operatorId]['estacoes'], true)) {
                $operatorsMap[$operatorId]['estacoes'][] = $stationName;
            }
        }

        foreach ($index->stationAggregates() as $stationName => $aggregate) {
            $operator = $aggregate['operator'] ?? null;

            if (!is_array($operator)) {
                continue;
            }

            $name = $operator['name'] ?? '';

            if (!is_string($name) || $name === '') {
                continue;
            }

            $operatorId = $this->stationOperatorId($stationName);

            if (!isset($operatorsMap[$operatorId])) {
                $operatorsMap[$operatorId] = [
                    'id' => $operatorId,
                    'nome' => $name,
                    'email' => $operator['email'] ?? OperatorReader::emailFromName($name),
                    'visivel' => true,
                    'ativo' => true,
                    'estacoes' => [],
                ];
            }

            if (!in_array($stationName, $operatorsMap[$operatorId]['estacoes'], true)) {
                $operatorsMap[$operatorId]['estacoes'][] = $stationName;
            }
        }

        foreach ($operatorsMap as &$op) {
            sort($op['estacoes']);
        }
        unset($op);

        return array_values($operatorsMap);
    }

    protected function stationOperatorId(string $stationName): string
    {
        $normalized = trim((string) $stationName);
        $withoutSuffix = preg_replace('/\d+$/', '', $normalized);

        return $withoutSuffix !== '' ? trim((string) $withoutSuffix) : $normalized;
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
