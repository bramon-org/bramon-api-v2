<?php

namespace App\Services;

class StationReader extends JsonFileReader
{
    /**
     * Get stations indexed by name for quick lookup.
     */
    public function getStationsIndexed(): array
    {
        $stations = $this->getData();
        $indexed = [];

        foreach ($stations as $station) {
            $name = $station['name'] ?? '';
            if ($name) {
                $indexed[$name] = $station;
            }
        }

        return $indexed;
    }

    /**
     * Get stations as a simple array.
     */
    public function getStations(): array
    {
        return $this->getData();
    }
}