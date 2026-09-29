#!/usr/bin/env php
<?php

/**
 * Generate test data for BRAMON API
 * 
 * Usage:
 * php generate_test_data.php --output-dir ./capturas --stations 5 --days 30
 */

// Increase memory limit for CLI scripts that process many files
ini_set('memory_limit', '512M');

require_once __DIR__ . '/vendor/autoload.php';

use App\Services\FilenameParser;

$options = getopt('', ['output-dir:', 'stations:', 'days:', 'help']);

if (isset($options['help'])) {
    echo "Usage: php generate_test_data.php --output-dir ./capturas --stations 5 --days 30\n";
    exit(0);
}

$outputDir = $options['output-dir'] ?? './capturas';
$stationCount = (int)($options['stations'] ?? 5);
$days = (int)($options['days'] ?? 30);

$stations = [];
for ($i = 1; $i <= $stationCount; $i++) {
    $stations[] = 'STA' . str_pad($i, 2, '0', STR_PAD_LEFT);
}

$fileTypes = [
    'metadata' => ['ext' => 'xml', 'suffix' => '1'],
    'preview' => ['ext' => 'jpg', 'suffix' => '1P'],
    'thumbnail' => ['ext' => 'jpg', 'suffix' => '1T'],
];

echo "Generating test data...\n";
echo "Output directory: $outputDir\n";
echo "Stations: " . implode(', ', $stations) . "\n";
echo "Days: $days\n";

$baseDate = new DateTime('-' . $days . ' days');
$endDate = new DateTime();

$captureCount = 0;

while ($baseDate <= $endDate) {
    $dateStr = $baseDate->format('Ymd');
    $year = $baseDate->format('Y');
    $month = $baseDate->format('m');
    $day = $baseDate->format('d');
    $yearMonth = $baseDate->format('Ym');

    foreach ($stations as $station) {
        // Random number of captures per station per day (0-5)
        $capturesToday = random_int(0, 5);
        
        for ($c = 0; $c < $capturesToday; $c++) {
            $time = random_int(0, 86399);
            $hour = (int)($time / 3600);
            $minute = (int)(($time % 3600) / 60);
            $second = $time % 60;
            $timeStr = sprintf('%02d%02d%02d', $hour, $minute, $second);
            
            $captureDateTime = DateTime::createFromFormat('YmdHis', $dateStr . $timeStr);
            $captureDateTimeStr = $captureDateTime->format('Y-m-d H:i:s');
            
            // Create directory structure
            $stationDir = "$outputDir/$station/$year/$yearMonth/$dateStr";
            if (!is_dir($stationDir)) {
                mkdir($stationDir, 0755, true);
            }
            
            // Generate files
            foreach ($fileTypes as $type => $info) {
                $filename = "M{$dateStr}_{$timeStr}_{$station}_{$info['suffix']}.{$info['ext']}";
                $filepath = "$stationDir/$filename";
                
                // Generate content based on type
                switch ($type) {
                    case 'metadata':
                        $content = generateXmlMetadata($station, $captureDateTimeStr, $dateStr, $timeStr);
                        break;
                    case 'preview':
                    case 'thumbnail':
                        $content = generateDummyImage($type === 'preview' ? 640 : 160, $type === 'preview' ? 480 : 120);
                        break;
                }
                
                file_put_contents($filepath, $content);
                $captureCount++;
            }
        }
    }
    
    $baseDate->modify('+1 day');
}

// Generate operators.json
$operators = [];
foreach ($stations as $i => $station) {
    $operators[] = [
        'id' => sprintf('%08x-%04x-%04x-%04x-%012x', 
            random_int(0, 0xffffffff),
            random_int(0, 0xffff),
            random_int(0x4000, 0x4fff),
            random_int(0x8000, 0xbfff),
            random_int(0, 0xffffffffffff)
        ),
        'nome' => "Operador $station",
        'email' => strtolower($station) . '@bramonmeteor.org',
        'visivel' => true,
        'ativo' => true,
        'estacao' => substr($station, 0, 3),
        'estacoes' => [$station],
    ];
}
file_put_contents("$outputDir/operators.json", json_encode($operators, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

// Generate stations.json
$stationsJson = [];
foreach ($stations as $station) {
    $stationsJson[] = [
        'id' => sprintf('%08x-%04x-%04x-%04x-%012x',
            random_int(0, 0xffffffff),
            random_int(0, 0xffff),
            random_int(0x4000, 0x4fff),
            random_int(0x8000, 0xbfff),
            random_int(0, 0xffffffffffff)
        ),
        'operador' => $operators[array_search($station, array_column($operators, 'estacao'))]['id'] ?? '',
        'source' => 'UFO',
        'name' => $station,
        'city' => 'Test City',
        'state' => 'Test State',
        'country' => 'BR',
        'latitude' => (string)(-27.5 + random_int(-100, 100) / 1000),
        'longitude' => (string)(-48.5 + random_int(-100, 100) / 1000),
        'azimuth' => (string)random_int(0, 360),
        'elevation' => (string)random_int(0, 90),
        'fov' => (string)random_int(60, 120),
        'camera_model' => 'Test Camera',
        'camera_lens' => 'Test Lens',
        'camera_capture' => 'Test Capture',
        'active' => true,
        'visible' => true,
    ];
}
file_put_contents("$outputDir/stations.json", json_encode($stationsJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo "Done! Generated $captureCount captures across " . count($stations) . " stations over $days days.\n";

function generateXmlMetadata(string $station, string $datetime, string $date, string $time): string
{
    $lat = -27.5904 + random_int(-1000, 1000) / 10000;
    $lng = -48.5925 + random_int(-1000, 1000) / 10000;
    $alt = random_int(0, 1000);
    
    return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<ufocapture_record 
    version="1.0"
    date="$date"
    time="$time"
    station="$station"
    lat="$lat"
    lng="$lng"
    alt="$alt"
    observer="Test Observer"
    sid="$station"
    cam="Test_Camera"
    lens="Test_Lens"
    cap="Test_Capture"
    comment="Test station $station"
/>
XML;
}

function generateDummyImage(int $width, int $height): string
{
    // Create a simple JPEG placeholder
    $img = imagecreatetruecolor($width, $height);
    $bgColor = imagecolorallocate($img, random_int(0, 50), random_int(0, 50), random_int(0, 50));
    imagefill($img, 0, 0, $bgColor);
    
    // Add some noise to simulate stars
    for ($i = 0; $i < 100; $i++) {
        $color = imagecolorallocate($img, random_int(100, 255), random_int(100, 255), random_int(100, 255));
        imagesetpixel($img, random_int(0, $width-1), random_int(0, $height-1), $color);
    }
    
    // Add timestamp text
    $textColor = imagecolorallocate($img, 255, 255, 255);
    imagestring($img, 3, 10, 10, date('Y-m-d H:i:s'), $textColor);
    
    ob_start();
    imagejpeg($img, null, 80);
    $data = ob_get_clean();
    imagedestroy($img);
    
    return $data;
}