<?php

namespace Tests\Unit;

use App\Services\FilenameParser;
use Tests\TestCase;

class FilenameParserTest extends TestCase
{
    public function testParseMetadataFile(): void
    {
        $result = FilenameParser::parse('M20250802_034905_TLP_1.xml');
        
        $this->assertNotNull($result);
        $this->assertEquals('20250802', $result['date']);
        $this->assertEquals('034905', $result['time']);
        $this->assertEquals('TLP', $result['station']);
        $this->assertEquals('metadata', $result['type']);
        $this->assertEquals('xml', $result['extension']);
    }

    public function testParsePreviewFile(): void
    {
        $result = FilenameParser::parse('M20250802_034905_TLP_1P.jpg');
        
        $this->assertNotNull($result);
        $this->assertEquals('preview', $result['type']);
        $this->assertEquals('jpg', $result['extension']);
    }

    public function testParseThumbnailFile(): void
    {
        $result = FilenameParser::parse('M20250802_034905_TLP_1T.bmp');
        
        $this->assertNotNull($result);
        $this->assertEquals('thumbnail', $result['type']);
        $this->assertEquals('bmp', $result['extension']);
    }

    public function testParseAnalyzeFile(): void
    {
        $result = FilenameParser::parse('M20250802_034905_TLP_1A.xml');
        
        $this->assertNotNull($result);
        $this->assertEquals('analyze', $result['type']);
    }

    public function testParseInvalidFilename(): void
    {
        $result = FilenameParser::parse('invalid_file.txt');
        
        $this->assertNull($result);
    }

    public function testParseCaseInsensitive(): void
    {
        $result = FilenameParser::parse('m20250802_034905_tlp_1p.JPG');
        
        $this->assertNotNull($result);
        $this->assertEquals('preview', $result['type']);
        $this->assertEquals('jpg', $result['extension']);
    }
}