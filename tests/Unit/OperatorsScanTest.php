<?php

namespace Tests\Unit;

use App\Console\Commands\OperatorsScan;
use App\Services\CaptureIndex;
use ReflectionClass;
use Tests\TestCase;

class OperatorsScanTest extends TestCase
{
    public function testCaptureObserverIsIncludedInGeneratedOperators(): void
    {
        $index = new class('unused') extends CaptureIndex
        {
            public function stationAggregates(): array
            {
                return [
                    'TLP1' => [
                        'operator' => [
                            'id' => 'lid-123',
                            'name' => 'Observer Name',
                            'email' => 'observer.name@bramonmeteor.org',
                        ],
                    ],
                ];
            }
        };

        $command = (new ReflectionClass(OperatorsScan::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(OperatorsScan::class, 'buildOperatorsFromStations');
        $method->setAccessible(true);

        $operators = $method->invoke($command, [[
            'name' => 'TLP1',
            'operador' => [
                'id' => substr(md5('op_TLP1'), 0, 32),
                'name' => 'Observer Name',
                'email' => 'observer.name@bramonmeteor.org',
            ],
        ]], $index);

        $this->assertCount(1, $operators);
        $this->assertSame('lid-123', $operators[0]['id']);
        $this->assertSame('Observer Name', $operators[0]['nome']);
        $this->assertSame(['TLP1'], $operators[0]['estacoes']);
    }
}