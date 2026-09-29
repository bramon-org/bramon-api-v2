<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\FileSystemReader;
use App\Services\FilenameParser;
use App\Services\DataProcessor;
use Illuminate\Support\Facades\Log;

class OperatorsScan extends Command
{
    protected $signature = 'bramon:operators-scan 
                            {--output= : Output file path (default: operators.json in base dir)}
                            {--base-dir= : Base directory for captures (default: config value)}
                            {--pretty : Pretty print JSON output}
                            {--force : Overwrite existing file without confirmation}';

    protected $description = 'Scan captures directory and generate operators.json from XML metadata';

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

        // Override file reader base dir if provided
        if ($this->option('base-dir')) {
            $this->fileReader = new \App\Services\FileSystemReader($baseDir, 0);
        }

        // Check if output file exists
        if (file_exists($outputFile) && !$force) {
            if (!$this->confirm("File {$outputFile} already exists. Overwrite?")) {
                $this->info('Operation cancelled.');
                return Command::SUCCESS;
            }
        }

        // Get all files
        $this->info('Reading filesystem...');
        $files = $this->fileReader->getFiles(false);

        if (empty($files)) {
            $this->warn('No files found in captures directory.');
            return Command::SUCCESS;
        }

        $this->info("Found " . count($files) . " files");

        // Process files to extract operator info from XML metadata
        $operators = $this->extractOperatorsFromFiles($files);

        if (empty($operators)) {
            $this->warn('No operators found in XML metadata. Generating fallback operators from stations...');
            $operators = $this->generateFallbackOperators($files);
        }

        // Write output file
        $json = $pretty 
            ? json_encode($operators, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
            : json_encode($operators, JSON_UNESCAPED_UNICODE);

        file_put_contents($outputFile, $json);

        $this->info("Generated operators.json with " . count($operators) . " operators");
        $this->info("Output file: {$outputFile}");

        // Display summary
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
     */
    protected function extractOperatorsFromFiles(array $files): array
    {
        $operatorsMap = [];

        // Filter only metadata XML files
        $metadataFiles = array_filter($files, fn($f) => $f['type'] === 'metadata' && $f['extension'] === 'xml');

        $this->info("Processing " . count($metadataFiles) . " metadata files...");

        $bar = $this->output->createProgressBar(count($metadataFiles));
        $bar->start();

        foreach ($metadataFiles as $file) {
            $operatorInfo = $this->extractOperatorFromXml($file['path']);
            
            if ($operatorInfo) {
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
                }
                
                // Add station to operator's station list
                $station = $file['station'];
                if (!in_array($station, $operatorsMap[$key]['estacoes'])) {
                    $operatorsMap[$key]['estacoes'][] = $station;
                }
            }
            
            $bar->advance();
        }
        
        $bar->finish();
        $this->newLine();

        // Sort stations for each operator
        foreach ($operatorsMap as &$op) {
            sort($op['estacoes']);
        }

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
            // Generate email preserving name part (replace non-alphanumeric with dots, remove leading/trailing dots)
            $emailBase = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '.', $nome), '.'));
            $email = $emailBase ? $emailBase . '@bramonmeteor.org' : 'operador@bramonmeteor.org';

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
     */
    protected function generateFallbackOperators(array $files): array
    {
        $stations = array_unique(array_column($files, 'station'));
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
}