<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use JsonException;

abstract class JsonFileReader
{
    protected string $filePath = '';
    protected int $lastModified = 0;
    protected array $data = [];

    public function __construct(string $filePath = '')
    {
        $this->filePath = $filePath;
    }

    /**
     * Get the data from the JSON file, reloading if modified.
     */
    public function getData(): array
    {
        if (!$this->filePath || !file_exists($this->filePath)) {
            return [];
        }

        $currentModified = filemtime($this->filePath);

        if ($currentModified > $this->lastModified) {
            $this->loadFile();
        }

        return $this->data;
    }

    /**
     * Force reload on next call.
     */
    public function invalidateCache(): void
    {
        $this->lastModified = 0;
        $this->data = [];
    }

    /**
     * Load and parse the JSON file.
     */
    protected function loadFile(): void
    {
        try {
            $content = file_get_contents($this->filePath);
            if ($content === false) {
                throw new \RuntimeException("Failed to read file: {$this->filePath}");
            }

            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

            if (!is_array($decoded)) {
                Log::warning("JSON file is not an array: {$this->filePath}");
                $this->data = [];
            } else {
                $this->data = $decoded;
                $this->lastModified = filemtime($this->filePath);
                Log::info("JSON file reloaded: {$this->filePath} ({$this->count()} items)");
            }
        } catch (JsonException $e) {
            Log::error("JSON decode error for {$this->filePath}: {$e->getMessage()}");
            $this->data = [];
        } catch (\Throwable $e) {
            Log::error("Error loading JSON file {$this->filePath}: {$e->getMessage()}");
            $this->data = [];
        }
    }

    /**
     * Get the count of items.
     */
    public function count(): int
    {
        return count($this->data);
    }
}