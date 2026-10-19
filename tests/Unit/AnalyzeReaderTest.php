<?php

namespace Tests\Unit;

use App\Services\AnalyzeReader;
use Tests\TestCase;

/**
 * Covers the reader of the UFOAnalyzer analysis file (the `A.xml` suffix).
 */
class AnalyzeReaderTest extends TestCase
{
    protected string $dir;

    protected AnalyzeReader $reader;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir() . '/bramon_analyze_test_' . getmypid() . '_' . bin2hex(random_bytes(4));
        @mkdir($this->dir, 0775, true);

        $this->reader = new AnalyzeReader;
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->dir);

        parent::tearDown();
    }

    /**
     * Write an analysis file and return its path.
     */
    protected function writeFile(string $name, string $contents): string
    {
        $path = $this->dir . '/' . $name;

        file_put_contents($path, $contents);

        return $path;
    }

    /**
     * The shape UFOAnalyzer writes: a `ufoanalyzer_record` root holding a
     * `ua2_objects` container with the analysed object.
     */
    protected function analysisXml(string $objectAttributes = 'fs="61" fe="70" sec="0.150150" av="5.019607" mag="-1.838501" class="J8_PPH"'): string
    {
        return <<<XML
        <?xml version="1.0" encoding="UTF-8" ?>
        <ufoanalyzer_record version="200" clip_name="M20190712_091431_TLP_5" o="1" y="2019" mo="7"
         d="12" h="9" m="14" s="31.340000" tz="0" tme="1.000000" lid="TLP" sid="5"
         lng="-48.592499" lat="-27.590401" alt="12.000000" cx="720" cy="480" fps="29.969999"
         frames="64" head="30" tail="30" observer="THIAGO_PAES" cam="Samsung_SCB-2000"
         lens="Fujimon_F0.95" cap="Dazzle_USB" memo="" az="180.000000" ev="45.000000"
         rot="0.000000" vx="56.000000" yx="1.000000" maxLev="255" maxMag="2.000000"
         minLev="50" mimMag="4.000000" dl="14" leap="40" pixs="141" rstar="0">
        	<ua2_objects>
        <ua2_object
         {$objectAttributes} m="0" dr="2.493300" dv="-33.687256" cdeg="0.012021"
         io="3" raP="102.169617" dcP="11.310469" x1="218.533554" y1="49.252487">
        	<ua2_objpath>
        <ua2_fdata2 fno=" 61" b=" 54" bm="000" Lsum="   492.7" mag="+1.28" az=" 167.2509785"></ua2_fdata2>
        	</ua2_objpath>
        </ua2_object>
        	</ua2_objects>
        </ufoanalyzer_record>
        XML;
    }

    public function testReadsClassificationAndAstronomicalFields(): void
    {
        $path = $this->writeFile('M20190712_091431_TLP_5A.XML', $this->analysisXml());

        $data = $this->reader->read($path);

        $this->assertNotNull($data);
        $this->assertSame('J8_PPH', $data['class']);
        $this->assertSame(61.0, $data['fs']);
        $this->assertSame(70.0, $data['fe']);
        $this->assertSame(0.150150, $data['sec']);
        $this->assertSame(5.019607, $data['av']);
        $this->assertSame(-1.838501, $data['mag']);
    }

    public function testReadsTheOlderUaRoot(): void
    {
        $path = $this->writeFile('M20190712_091431_TLP_5A.XML', <<<'XML'
        <?xml version="1.0"?>
        <UA lat="12.345" lng="67.890" az="160" ev="30" alt="0" rot="0" cx="0" cy="0" vx="52.23">
            <ua2_objects>
                <ua2_object fps="25" frames="100" dc1="0" dc2="0" class="spo" />
            </ua2_objects>
        </UA>
        XML);

        $data = $this->reader->read($path);

        $this->assertNotNull($data);
        $this->assertSame('spo', $data['class']);
    }

    public function testObjectWithoutClassKeepsTheOtherFields(): void
    {
        $path = $this->writeFile('M20190712_091431_TLP_5A.XML', $this->analysisXml('fs="10" fe="20" mag="1.5"'));

        $data = $this->reader->read($path);

        $this->assertNotNull($data);
        $this->assertNull($data['class']);
        $this->assertSame(10.0, $data['fs']);
        $this->assertSame(1.5, $data['mag']);
    }

    public function testNonNumericValuesBecomeNull(): void
    {
        $path = $this->writeFile('M20190712_091431_TLP_5A.XML', $this->analysisXml('fs="n/a" mag="unknown" class="J8_NVI"'));

        $data = $this->reader->read($path);

        $this->assertNotNull($data);
        $this->assertNull($data['fs']);
        $this->assertNull($data['mag']);
        $this->assertSame('J8_NVI', $data['class']);
    }

    public function testAcceptsSingleQuotedAttributes(): void
    {
        $path = $this->writeFile('M20190712_091431_TLP_5A.XML', <<<'XML'
        <?xml version="1.0"?>
        <ufoanalyzer_record version="200">
            <ua2_objects>
                <ua2_object fs='61' class='J8_APX' />
            </ua2_objects>
        </ufoanalyzer_record>
        XML);

        $data = $this->reader->read($path);

        $this->assertNotNull($data);
        $this->assertSame('J8_APX', $data['class']);
        $this->assertSame(61.0, $data['fs']);
    }

    public function testFindsTheObjectBeyondTheHeadWindow(): void
    {
        // A long trajectory pushes the analysed object past the first chunk.
        $padding = str_repeat('<ua2_fdata2 fno=" 1" b=" 1" bm="000" Lsum=" 1.0"></ua2_fdata2>' . "\n", 2000);

        $path = $this->writeFile('M20190712_091431_TLP_5A.XML', <<<'XML'
        <?xml version="1.0"?>
        <ufoanalyzer_record version="200">
            <ua2_objects>
        {$padding}
                <ua2_object fs="61" class="J8_SZC" />
            </ua2_objects>
        </ufoanalyzer_record>
        XML);

        $data = $this->reader->read($path);

        $this->assertNotNull($data);
        $this->assertSame('J8_SZC', $data['class']);
    }

    public function testCarriesDataTellsAnEmptyAnalysisApart(): void
    {
        $path = $this->writeFile('M20190712_091431_TLP_5A.XML', $this->analysisXml('fps="25" frames="100"'));

        $analysis = $this->reader->read($path);

        $this->assertNotNull($analysis);
        $this->assertFalse(AnalyzeReader::carriesData($analysis));
        $this->assertFalse(AnalyzeReader::carriesData(null));
    }

    public function testCarriesDataAcceptsAnAnalysisWithAValue(): void
    {
        $path = $this->writeFile('M20190712_091431_TLP_5A.XML', $this->analysisXml('class="J8_PPH"'));

        $this->assertTrue(AnalyzeReader::carriesData($this->reader->read($path)));
    }

    public function testFindsTheObjectSplitAcrossTheHeadBoundary(): void
    {
        // The analysed object opens right before the head window ends, so the
        // tag is cut in half by the first read. The trajectory below makes the
        // file bigger than a single chunk, so the tag has to survive the
        // boundary between two reads.
        $head = str_repeat('a', 16300);
        $trajectory = str_repeat('<ua2_fdata2 fno=" 1" b=" 1"></ua2_fdata2>', 3000);

        $path = $this->writeFile('M20190712_091431_TLP_5A.XML', <<<XML
        <?xml version="1.0"?>
        <ufoanalyzer_record version="200">
            <ua2_objects>
        {$head}<ua2_object fs="61" class="J8_SZC" />
        {$trajectory}
            </ua2_objects>
        </ufoanalyzer_record>
        XML);

        $data = $this->reader->read($path);

        $this->assertNotNull($data);
        $this->assertSame('J8_SZC', $data['class']);
        $this->assertSame(61.0, $data['fs']);
    }

    public function testReturnsNullForAMissingFile(): void
    {
        $this->assertNull($this->reader->read($this->dir . '/does-not-exist.XML'));
    }

    public function testReturnsNullForAnEmptyFile(): void
    {
        $path = $this->writeFile('empty.XML', '');

        $this->assertNull($this->reader->read($path));
    }

    public function testReturnsNullForAFileWithoutAnAnalysedObject(): void
    {
        $path = $this->writeFile('no-object.XML', '<?xml version="1.0"?><ufoanalyzer_record version="200"></ufoanalyzer_record>');

        $this->assertNull($this->reader->read($path));
    }

    public function testReturnsNullForAMalformedFile(): void
    {
        $path = $this->writeFile('broken.XML', '<?xml version="1.0"?><ufoanalyzer_record><ua2_object class="J8');

        $this->assertNull($this->reader->read($path));
    }
}
