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
     * Derive a BRAMON address from an operator name.
     *
     * No capture ever carries an e-mail: the metadata tag only records the
     * observer name, so the address is always a slug of that name. Shared by
     * the indexer and by the operators scan so both derive the same value.
     */
    public static function emailFromName(string $name): string
    {
        $base = strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '.', $name), '.'));

        return $base !== '' ? $base . '@bramonmeteor.org' : 'operador@bramonmeteor.org';
    }

    /**
     * Find stations linked to an operator.
     * Priority: 1. explicit 'estacoes' list, 2. 'estacao' prefix
     *
     * @param  array<string,mixed>  $operator
     * @param  array<string,mixed>  $availableStations  station records indexed by name
     * @return array<int,string>
     */
    public function getLinkedStations(array $operator, array $availableStations): array
    {
        return $this->getLinkedStationNames($operator, array_keys($availableStations));
    }

    /**
     * Same linking rules as getLinkedStations(), starting from a plain list of
     * station names instead of a name-indexed map.
     *
     * @param  array<string,mixed>  $operator
     * @param  array<int,string>  $stationNames
     * @return array<int,string>
     */
    public function getLinkedStationNames(array $operator, array $stationNames): array
    {
        // Priority 1: explicit 'estacoes' list
        if (!empty($operator['estacoes']) && is_array($operator['estacoes'])) {
            return array_values(array_filter(
                $operator['estacoes'],
                fn($s) => is_string($s) && in_array($s, $stationNames, true)
            ));
        }

        // Priority 2: fallback to 'estacao' prefix
        $prefix = $operator['estacao'] ?? $operator['station'] ?? '';
        if (is_string($prefix) && $prefix !== '') {
            return array_values(array_filter($stationNames, fn($s) => str_starts_with($s, $prefix)));
        }

        return [];
    }

    /**
     * Find the operator responsible for a station, based on the station name.
     *
     * This is the reverse of getLinkedStationNames(), so /stations and /operators
     * always agree on who owns what. Priority, per operator:
     * 1. the explicit 'estacoes' list contains the station name
     * 2. the station name starts with the operator's 'estacao' prefix
     *
     * An operator with an explicit 'estacoes' list never falls back to its
     * prefix, mirroring the priority used in the operator -> station direction.
     * The first operator in file order wins.
     *
     * @param  array<int,array<string,mixed>>  $operators
     * @return array<string,mixed>|null the raw operator entry, or null when unowned
     */
    public function findOperatorForStation(string $stationName, array $operators): ?array
    {
        if ($stationName === '') {
            return null;
        }

        $prefixMatch = null;

        foreach ($operators as $operator) {
            if (!is_array($operator)) {
                continue;
            }

            if (!empty($operator['estacoes']) && is_array($operator['estacoes'])) {
                foreach ($operator['estacoes'] as $linked) {
                    if (is_string($linked) && strcasecmp($linked, $stationName) === 0) {
                        return $operator;
                    }
                }

                continue;
            }

            $prefix = $operator['estacao'] ?? $operator['station'] ?? '';

            if ($prefixMatch === null && is_string($prefix) && $prefix !== '' && str_starts_with($stationName, $prefix)) {
                $prefixMatch = $operator;
            }
        }

        return $prefixMatch;
    }
}