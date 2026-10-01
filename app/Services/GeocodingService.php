<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class GeocodingService
{
    protected const CACHE_TTL = 86400 * 30; // 30 days
    protected const NOMINATIM_URL = 'https://nominatim.openstreetmap.org/reverse';
    protected const IBGE_BNGB_URL = 'https://servicodados.ibge.gov.br/api/v1/bngb/proximidade';

    /**
     * Reverse geocode latitude/longitude to city and state.
     *
     * Tries IBGE BNGB first, falls back to Nominatim (OpenStreetMap).
     *
     * @return array{city: string, state: string, country: string, source: string}
     */
    public function reverseGeocode(float $latitude, float $longitude): array
    {
        $cacheKey = "geocode_{$latitude}_{$longitude}";

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($latitude, $longitude) {
            // Try IBGE BNGB first
            $ibgeResult = $this->tryIbge($latitude, $longitude);
            if ($ibgeResult !== null) {
                return $ibgeResult;
            }

            // Fallback to Nominatim
            return $this->tryNominatim($latitude, $longitude);
        });
    }

    /**
     * Try reverse geocoding using IBGE BNGB API.
     */
    protected function tryIbge(float $latitude, float $longitude): ?array
    {
        // Try with "Limites e Localidades" category, searching for cities/municipalities
        // Use a reasonable radius (50km) to find nearby localities
        $url = sprintf(
            '%s/%F/%F/50/nomesgeograficos',
            self::IBGE_BNGB_URL,
            $latitude,
            $longitude
        );

        try {
            $response = Http::timeout(10)
                ->accept('application/json')
                ->get($url, [
                    'categoria' => 'Limites e Localidades',
                    'classe' => 'cidade',
                ]);

            if (!$response->successful()) {
                return null;
            }

            $data = $response->json();

            if (!isset($data['features']) || !is_array($data['features']) || empty($data['features'])) {
                // Try with municipio class
                $response = Http::timeout(10)
                    ->accept('application/json')
                    ->get($url, [
                        'categoria' => 'Limites e Localidades',
                        'classe' => 'municipio',
                    ]);

                if (!$response->successful()) {
                    return null;
                }

                $data = $response->json();

                if (!isset($data['features']) || !is_array($data['features']) || empty($data['features'])) {
                    return null;
                }
            }

            // Find the closest feature with a valid name
            $closest = $this->findClosestFeature($data['features'], $latitude, $longitude);
            if ($closest === null) {
                return null;
            }

            $properties = $closest['properties'] ?? [];
            $name = $properties['nome'] ?? $properties['name'] ?? '';

            if ($name === '') {
                return null;
            }

            // Extract state from IBGE data if available
            $state = $this->extractStateFromIbge($properties);

            return [
                'city' => $name,
                'state' => $state ?? 'Unknown',
                'country' => 'BR',
                'source' => 'ibge',
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Try reverse geocoding using Nominatim (OpenStreetMap).
     */
    protected function tryNominatim(float $latitude, float $longitude): array
    {
        try {
            $response = Http::timeout(10)
                ->accept('application/json')
                ->withHeaders([
                    'User-Agent' => 'BRAMON-API/1.0 (https://bramonmeteor.org)',
                ])
                ->get(self::NOMINATIM_URL, [
                    'format' => 'json',
                    'lat' => $latitude,
                    'lon' => $longitude,
                    'addressdetails' => 1,
                ]);

            if (!$response->successful()) {
                return $this->defaultResult();
            }

            $data = $response->json();
            $address = $data['address'] ?? [];

            // Extract city - try multiple possible fields
            $city = $address['city']
                ?? $address['town']
                ?? $address['village']
                ?? $address['municipality']
                ?? $address['county']
                ?? 'Unknown';

            // Extract state
            $state = $address['state'] ?? $address['province'] ?? 'Unknown';

            // Normalize Brazilian state names to UF codes if needed
            $state = $this->normalizeState($state);

            return [
                'city' => $city,
                'state' => $state,
                'country' => strtoupper($address['country_code'] ?? 'BR'),
                'source' => 'nominatim',
            ];
        } catch (\Throwable) {
            return $this->defaultResult();
        }
    }

    /**
     * Find the closest feature to the given coordinates.
     */
    protected function findClosestFeature(array $features, float $lat, float $lon): ?array
    {
        $closest = null;
        $minDistance = INF;

        foreach ($features as $feature) {
            $geometry = $feature['geometry'] ?? null;
            if (!$geometry || !isset($geometry['coordinates'])) {
                continue;
            }

            $coords = $geometry['coordinates'];
            // GeoJSON coordinates are [longitude, latitude]
            $featureLon = $coords[0] ?? null;
            $featureLat = $coords[1] ?? null;

            if ($featureLat === null || $featureLon === null) {
                continue;
            }

            $distance = $this->haversineDistance($lat, $lon, $featureLat, $featureLon);
            if ($distance < $minDistance) {
                $minDistance = $distance;
                $closest = $feature;
            }
        }

        return $closest;
    }

    /**
     * Calculate haversine distance between two points in km.
     */
    protected function haversineDistance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371; // km

        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) * sin($dLat / 2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($dLon / 2) * sin($dLon / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }

    /**
     * Extract state from IBGE feature properties.
     */
    protected function extractStateFromIbge(array $properties): ?string
    {
        // IBGE features might have UF/state information in properties
        return $properties['uf']
            ?? $properties['sigla_uf']
            ?? $properties['estado']
            ?? $properties['state']
            ?? null;
    }

    /**
     * Normalize Brazilian state names to UF codes.
     */
    protected function normalizeState(string $state): string
    {
        $stateMap = [
            'acre' => 'AC',
            'alagoas' => 'AL',
            'amapá' => 'AP',
            'amazonas' => 'AM',
            'bahia' => 'BA',
            'ceará' => 'CE',
            'distrito federal' => 'DF',
            'espírito santo' => 'ES',
            'goiás' => 'GO',
            'maranhão' => 'MA',
            'mato grosso' => 'MT',
            'mato grosso do sul' => 'MS',
            'minas gerais' => 'MG',
            'pará' => 'PA',
            'paraíba' => 'PB',
            'paraná' => 'PR',
            'pernambuco' => 'PE',
            'piauí' => 'PI',
            'rio de janeiro' => 'RJ',
            'rio grande do norte' => 'RN',
            'rio grande do sul' => 'RS',
            'rondônia' => 'RO',
            'roraima' => 'RR',
            'santa catarina' => 'SC',
            'são paulo' => 'SP',
            'sergipe' => 'SE',
            'tocantins' => 'TO',
        ];

        $normalized = strtolower(trim($state));
        return $stateMap[$normalized] ?? strtoupper($state);
    }

    /**
     * Default result when all geocoding fails.
     */
    protected function defaultResult(): array
    {
        return [
            'city' => 'Unknown',
            'state' => 'Unknown',
            'country' => 'BR',
            'source' => 'none',
        ];
    }
}