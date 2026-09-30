<?php

namespace App\Services;

use Generator;
use RuntimeException;
use SplPriorityQueue;

/**
 * Bounded-memory external sorter.
 *
 * Records are buffered up to $chunkSize entries, sorted in memory and spilled
 * to a temporary file. A k-way merge over the spilled chunks then produces the
 * final ordered stream, so peak memory is O($chunkSize + $chunkCount) instead
 * of O($totalRecords).
 *
 * Used by the indexing commands so they never hold the whole dataset at once.
 */
class ExternalSort
{
    protected int $chunkSize;

    protected bool $descending;

    /** @var array<int,array{0:string,1:array<string,mixed>}> */
    protected array $buffer = [];

    /** @var array<int,string> */
    protected array $chunkFiles = [];

    protected int $totalAdded = 0;

    public function __construct(int $chunkSize = 20000, bool $descending = true)
    {
        $this->chunkSize = max(1, $chunkSize);
        $this->descending = $descending;
    }

    /**
     * Queue a record for sorting.
     *
     * @param  array<string,mixed>  $payload
     */
    public function add(string $key, array $payload): void
    {
        $this->buffer[] = [$key, $payload];
        $this->totalAdded++;

        if (count($this->buffer) >= $this->chunkSize) {
            $this->spill();
        }
    }

    /**
     * Total number of queued records.
     */
    public function count(): int
    {
        return $this->totalAdded;
    }

    /**
     * Sort the in-memory buffer and write it to a temp chunk file.
     */
    protected function spill(): void
    {
        if ($this->buffer === []) {
            return;
        }

        $this->sortBuffer();

        $path = $this->tempPath();
        $handle = @fopen($path, 'wb');

        if ($handle === false) {
            throw new RuntimeException("Unable to create sort chunk: {$path}");
        }

        foreach ($this->buffer as [$key, $payload]) {
            $line = json_encode([$key, $payload], JSON_UNESCAPED_UNICODE);
            fwrite($handle, $line . "\n");
        }

        fclose($handle);
        $this->chunkFiles[] = $path;

        // Release the buffer references before continuing.
        $this->buffer = [];
    }

    /**
     * Sort the buffer by key honouring the sort direction.
     */
    protected function sortBuffer(): void
    {
        if ($this->descending) {
            usort($this->buffer, fn($a, $b) => strcmp($b[0], $a[0]));
        } else {
            usort($this->buffer, fn($a, $b) => strcmp($a[0], $b[0]));
        }
    }

    /**
     * Yield every queued record in sorted order.
     *
     * @return Generator<int,array{0:string,1:array<string,mixed>}>
     */
    public function sorted(): Generator
    {
        // Fast path: everything fit in memory.
        if ($this->chunkFiles === []) {
            $this->sortBuffer();

            foreach ($this->buffer as $record) {
                yield $record;
            }

            $this->buffer = [];

            return;
        }

        $this->spill();

        yield from $this->merge();

        $this->cleanup();
    }

    /**
     * K-way merge across the spilled chunk files.
     *
     * @return Generator<int,array{0:string,1:array<string,mixed>}>
     */
    protected function merge(): Generator
    {
        // SplPriorityQueue is a max-heap, so ordering is delegated to an
        // explicit comparator to support both directions. The sort key is used
        // as the priority, so the payload never affects ordering.
        $descending = $this->descending;

        $queue = new class($descending) extends SplPriorityQueue {
            private bool $descending;

            public function __construct(bool $descending)
            {
                $this->descending = $descending;
            }

            /**
             * @param  mixed  $a  Element value (a [key, payload, chunk] tuple).
             * @param  mixed  $b
             */
            public function compare(mixed $a, mixed $b): int
            {
                $cmp = strcmp(self::keyOf($a), self::keyOf($b));

                return $this->descending ? $cmp : -$cmp;
            }

            /**
             * compare() receives the element values, not the priorities. Fall
             * back to stringifying so a bare string never breaks comparison.
             */
            private static function keyOf(mixed $value): string
            {
                if (is_array($value)) {
                    return (string) ($value[0] ?? '');
                }

                return (string) $value;
            }
        };

        $queue->setExtractFlags(SplPriorityQueue::EXTR_DATA);

        /** @var array<int,resource> $handles */
        $handles = [];

        foreach ($this->chunkFiles as $i => $path) {
            $handle = @fopen($path, 'rb');

            if ($handle === false) {
                continue;
            }

            $handles[$i] = $handle;
            $this->seedQueue($queue, $handles[$i], $i);
        }

        while (!$queue->isEmpty()) {
            /** @var array{0:string,1:array<string,mixed>,2:int} $top */
            $top = $queue->extract();
            [$key, $payload, $i] = $top;

            yield [$key, $payload];

            if (isset($handles[$i])) {
                $this->seedQueue($queue, $handles[$i], $i);
            }
        }

        foreach ($handles as $handle) {
            fclose($handle);
        }
    }

    /**
     * Read the next line from a chunk handle and push it onto the queue.
     *
     * @param  SplPriorityQueue  $queue
     * @param  resource  $handle
     */
    protected function seedQueue(SplPriorityQueue $queue, $handle, int $i): void
    {
        $line = fgets($handle);

        if ($line === false) {
            return;
        }

        $decoded = json_decode($line, true);

        if (is_array($decoded) && isset($decoded[0], $decoded[1])) {
            // Note the argument order: SplPriorityQueue::insert() takes the
            // value first and the priority second (the reverse of what the
            // documentation suggests).
            $queue->insert(
                [
                    (string) $decoded[0],
                    $decoded[1],
                    $i,
                ],
                (string) $decoded[0]
            );
        }
    }

    /**
     * Remove all temporary chunk files.
     */
    protected function cleanup(): void
    {
        foreach ($this->chunkFiles as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        $this->chunkFiles = [];
    }

    /**
     * Build a unique temp file path.
     */
    protected function tempPath(): string
    {
        $dir = sys_get_temp_dir();

        if (!is_writable($dir)) {
            throw new RuntimeException("Temp directory is not writable: {$dir}");
        }

        return $dir . '/bramon_sort_' . getmypid() . '_' . bin2hex(random_bytes(6)) . '.ndjson';
    }
}
