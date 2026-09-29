<?php

namespace App\Services;

class OperatorReader extends JsonFileReader
{
    /**
     * Get operators with explicit station list taking priority over prefix.
     */
    public function getOperators(): array
    {
        return $this->getData();
    }

    /**
     * Find stations linked to an operator.
     * Priority: 1. explicit 'estacoes' list, 2. 'estacao' prefix
     */
    public function getLinkedStations(array $operator, array $availableStations): array
    {
        $stationNames = array_keys($availableStations);

        // Priority 1: explicit 'estacoes' list
        if (!empty($operator['estacoes']) && is_array($operator['estacoes'])) {
            return array_filter($operator['estacoes'], fn($s) => in_array($s, $stationNames, true));
        }

        // Priority 2: fallback to 'estacao' prefix
        $prefix = $operator['estacao'] ?? $operator['station'] ?? '';
        if ($prefix) {
            return array_filter($stationNames, fn($s) => str_starts_with($s, $prefix));
        }

        return [];
    }
}