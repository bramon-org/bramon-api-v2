<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class FileSystemReader
{
    protected string $baseDir;
    protected ?array $cache = null;
    protected ?int $cacheTime = null;
    protected int $cacheTtl;
    protected int $maxCacheSize = 10000; // Max files to cache in memory

    public function __construct(string $baseDir, int $cacheTtl = 60)
    {
        $this->baseDir = rtrim($baseDir, '/');
        $this->cacheTtl = $cacheTtl;
    }

    /**
     * Get all files from the filesystem with caching.
     * WARNING: Loads all files into memory. Use getFilesGenerator() for large directories.
     */
    public function getFiles(bool $useCache = true): array
    {
        if ($useCache && $this->isCacheValid()) {
            return $this->cache ?? [];
        }

        $files = $this->scanFiles();
        
        // Only cache if within size limit to prevent memory exhaustion
        if ($useCache && count($files) <= $this->maxCacheSize) {
            $this->cache = $files;
            $this->cacheTime = time();
        } elseif ($useCache) {
            Log::warning("File count ({$this->maxCacheSize}) exceeds cache limit ({$this->maxCacheSize}), skipping memory cache");
        }

        return $files;
    }

    /**
     * Get files as a generator to avoid memory issues with large directories.
     * This is the preferred method for processing large datasets.
     */
    public function getFilesGenerator(bool $useCache = true): \Generator
    {
        if ($useCache && $this->isCacheValid() && $this->cache !== null) {
            foreach ($this->cache as $file) {
                yield $file;
            }
            return;
        }

        $files = [];
        $count = 0;

        if (!is_dir($this->baseDir)) {
            Log::warning("Base directory does not exist: {$this->baseDir}");
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->baseDir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $filePath = $file->getPathname();
            $relPath = str_replace('\\', '/', ltrim(substr($filePath, strlen($this->baseDir) + 1), '/'));

            // Skip hidden files/directories
            if (preg_match('/(^|\/)\./', $relPath)) {
                continue;
            }

            $fileInfo = $this->getFileInfo($filePath, $relPath);
            if ($fileInfo) {
                if ($useCache && $count < $this->maxCacheSize) {
                    $files[] = $fileInfo;
                }
                $count++;
                yield $fileInfo;
            }
        }

        if ($useCache && $count <= $this->maxCacheSize) {
            $this->cache = $files;
            $this->cacheTime = time();
        }
    }

    /**
     * Get files filtered by type, using generator for memory efficiency.
     */
    public function getFilesByType(string $type, bool $useCache = true): \Generator
    {
        foreach ($this->getFilesGenerator($useCache) as $file) {
            if ($file['type'] === $type) {
                yield $file;
            }
        }
    }

    /**
     * Get files for a specific station, using generator for memory efficiency.
     */
    public function getFilesByStation(string $station, bool $useCache = true): \Generator
    {
        foreach ($this->getFilesGenerator($useCache) as $file) {
            if ($file['station'] === $station) {
                yield $file;
            }
        }
    }

    /**
     * Count total files without loading them all into memory.
     */
    public function countFiles(): int
    {
        $count = 0;
        foreach ($this->getFilesGenerator(false) as $_) {
            $count++;
        }
        return $count;
    }

    /**
     * Scan the filesystem for capture files (loads all into memory).
     * Use only when file count is known to be small.
     */
    protected function scanFiles(): array
    {
        $files = [];

        if (!is_dir($this->baseDir)) {
            Log::warning("Base directory does not exist: {$this->baseDir}");
            return $files;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->baseDir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $filePath = $file->getPathname();
            $relPath = str_replace('\\', '/', ltrim(substr($filePath, strlen($this->baseDir) + 1), '/'));

            // Skip hidden files/directories
            if (preg_match('/(^|\/)\./', $relPath)) {
                continue;
            }

            $fileInfo = $this->getFileInfo($filePath, $relPath);
            if ($fileInfo) {
                $files[] = $fileInfo;
            }
        }

        return $files;
    }

    /**
     * Extract file information from a file path.
     */
    protected function getFileInfo(string $filePath, string $relPath): ?array
    {
        $filename = basename($filePath);
        
        // Fast pre-check before expensive parsing
        if (!FilenameParser::isValidFormat($filename)) {
            return null;
        }

        $parsed = FilenameParser::parse($filename);

        if (!$parsed) {
            return null;
        }

        // Extract station name from directory structure (first path component)
        $pathParts = explode('/', $relPath);
        $stationName = $pathParts[0] ?? $parsed['station'];

        try {
            $stat = stat($filePath);

            return [
                'path' => $filePath,
                'rel_path' => $relPath,
                'filename' => $filename,
                'size' => $stat['size'] ?? 0,
                'modified_at' => date('c', $stat['mtime'] ?? time()),
                'station' => $stationName,
                'date' => $parsed['date'],
                'time' => $parsed['time'],
                'sequence' => $parsed['sequence'],
                'type' => $parsed['type'],
                'extension' => $parsed['extension'],
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Check if cache is still valid.
     */
    protected function isCacheValid(): bool
    {
        if ($this->cache === null || $this->cacheTime === null) {
            return false;
        }

        return (time() - $this->cacheTime) < $this->cacheTtl;
    }

    /**
     * Get the base directory path.
     */
    public function getBaseDir(): string
    {
        return $this->baseDir;
    }

    /**
     * Invalidate the cache.
     */
    public function invalidateCache(): void
    {
        $this->cache = null;
        $this->cacheTime = null;
    }

    /**
     * Set maximum cache size (number of files).
     */
    public function setMaxCacheSize(int $size): void
    {
        $this->maxCacheSize = $size;
    }
}