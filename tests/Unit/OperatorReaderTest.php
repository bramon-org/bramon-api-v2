<?php

namespace Tests\Unit;

use App\Services\OperatorReader;
use Tests\TestCase;

class OperatorReaderTest extends TestCase
{
    public function testGetLinkedStationsExplicitList(): void
    {
        $operator = [
            'estacoes' => ['TLP1', 'TLP2', 'TLP3'],
            'estacao' => 'TLP',
        ];
        
        $availableStations = [
            'TLP1' => ['name' => 'TLP1'],
            'TLP2' => ['name' => 'TLP2'],
            'STA1' => ['name' => 'STA1'],
        ];
        
        $reader = new OperatorReader();
        $reflection = new \ReflectionClass($reader);
        $method = $reflection->getMethod('getLinkedStations');
        $method->setAccessible(true);
        
        $linked = $method->invoke($reader, $operator, $availableStations);
        
        $this->assertCount(2, $linked);
        $this->assertContains('TLP1', $linked);
        $this->assertContains('TLP2', $linked);
        $this->assertNotContains('TLP3', $linked); // Not in available stations
        $this->assertNotContains('STA1', $linked); // Not in explicit list
    }

    public function testGetLinkedStationsPrefixFallback(): void
    {
        $operator = [
            'estacao' => 'TLP',
        ];
        
        $availableStations = [
            'TLP1' => ['name' => 'TLP1'],
            'TLP2' => ['name' => 'TLP2'],
            'STA1' => ['name' => 'STA1'],
        ];
        
        $reader = new OperatorReader();
        $reflection = new \ReflectionClass($reader);
        $method = $reflection->getMethod('getLinkedStations');
        $method->setAccessible(true);
        
        $linked = $method->invoke($reader, $operator, $availableStations);
        
        $this->assertCount(2, $linked);
        $this->assertContains('TLP1', $linked);
        $this->assertContains('TLP2', $linked);
        $this->assertNotContains('STA1', $linked);
    }

    public function testExplicitListTakesPriorityOverPrefix(): void
    {
        $operator = [
            'estacoes' => ['STA1'], // Explicit list has STA1
            'estacao' => 'TLP',      // Prefix would match TLP1, TLP2
        ];
        
        $availableStations = [
            'TLP1' => ['name' => 'TLP1'],
            'TLP2' => ['name' => 'TLP2'],
            'STA1' => ['name' => 'STA1'],
        ];
        
        $reader = new OperatorReader();
        $reflection = new \ReflectionClass($reader);
        $method = $reflection->getMethod('getLinkedStations');
        $method->setAccessible(true);
        
        $linked = $method->invoke($reader, $operator, $availableStations);
        
        // Explicit list takes priority
        $this->assertCount(1, $linked);
        $this->assertContains('STA1', $linked);
        $this->assertNotContains('TLP1', $linked);
        $this->assertNotContains('TLP2', $linked);
    }
}