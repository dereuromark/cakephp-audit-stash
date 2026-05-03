<?php

declare(strict_types=1);

namespace AuditStash\Test\TestCase\Service;

use AuditStash\Service\ExportService;
use Cake\Core\Configure;
use Cake\Http\Exception\BadRequestException;
use Cake\I18n\DateTime;
use Cake\TestSuite\TestCase;
use RuntimeException;

class ExportServiceTest extends TestCase
{
    protected array $fixtures = ['plugin.AuditStash.AuditLogs'];

    protected function tearDown(): void
    {
        Configure::delete('AuditStash.export');
        parent::tearDown();
    }

    public function testApplyDefaultDateRangeAddsFloorWhenNeitherBoundSet(): void
    {
        $service = new ExportService();
        $query = $this->fetchTable('AuditStash.AuditLogs')->find();

        $floor = $service->applyDefaultDateRange($query, null, null);

        $this->assertInstanceOf(DateTime::class, $floor);
    }

    public function testApplyDefaultDateRangeIsNoOpWhenDateFromSet(): void
    {
        $service = new ExportService();
        $query = $this->fetchTable('AuditStash.AuditLogs')->find();

        $floor = $service->applyDefaultDateRange($query, '2025-01-01', null);

        $this->assertNull($floor);
    }

    public function testApplyDefaultDateRangeIsNoOpWhenDateToSet(): void
    {
        $service = new ExportService();
        $query = $this->fetchTable('AuditStash.AuditLogs')->find();

        $floor = $service->applyDefaultDateRange($query, null, '2025-12-31');

        $this->assertNull($floor);
    }

    public function testHardCapFromConfigOverridesDefault(): void
    {
        Configure::write('AuditStash.export.hardCap', 42);

        $this->assertSame(42, (new ExportService())->hardCap());
    }

    public function testAssertWithinHardCapAcceptsExactCap(): void
    {
        Configure::write('AuditStash.export.hardCap', 100);

        // No exception means pass.
        (new ExportService())->assertWithinHardCap(100);
        $this->expectNotToPerformAssertions();
    }

    public function testAssertWithinHardCapThrowsBeyondCap(): void
    {
        Configure::write('AuditStash.export.hardCap', 100);

        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage('exceeding the hard cap of 100');

        (new ExportService())->assertWithinHardCap(101);
    }

    public function testStreamCsvWritesHeaderAndRows(): void
    {
        $this->seedRows(3);

        $service = new ExportService();
        $query = $this->fetchTable('AuditStash.AuditLogs')->find();

        $stream = fopen('php://memory', 'r+');
        $service->stream($query, 'csv', $stream);
        rewind($stream);
        $output = stream_get_contents($stream);
        fclose($stream);

        $lines = array_filter(explode("\n", trim($output)));
        $this->assertCount(4, $lines, 'header + 3 data rows');
        $this->assertStringStartsWith('id,transaction_key,type,source,', $lines[0]);
    }

    public function testStreamNdjsonEmitsOneJsonObjectPerLine(): void
    {
        $this->seedRows(2);

        $service = new ExportService();
        $query = $this->fetchTable('AuditStash.AuditLogs')->find();

        $stream = fopen('php://memory', 'r+');
        $service->stream($query, 'ndjson', $stream);
        rewind($stream);
        $output = stream_get_contents($stream);
        fclose($stream);

        $lines = array_filter(explode("\n", trim($output)));
        $this->assertCount(2, $lines);
        foreach ($lines as $line) {
            $row = json_decode($line, true);
            $this->assertIsArray($row);
            $this->assertArrayHasKey('id', $row);
        }
    }

    public function testStreamJsonEmitsValidArray(): void
    {
        $this->seedRows(2);

        $service = new ExportService();
        $query = $this->fetchTable('AuditStash.AuditLogs')->find();

        $stream = fopen('php://memory', 'r+');
        $service->stream($query, 'json', $stream);
        rewind($stream);
        $output = stream_get_contents($stream);
        fclose($stream);

        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded);
        $this->assertCount(2, $decoded);
        $this->assertArrayHasKey('id', $decoded[0]);
    }

    public function testStreamUnsupportedFormatThrows(): void
    {
        $service = new ExportService();
        $query = $this->fetchTable('AuditStash.AuditLogs')->find();
        $stream = fopen('php://memory', 'r+');

        try {
            $this->expectException(RuntimeException::class);
            $service->stream($query, 'xml', $stream);
        } finally {
            fclose($stream);
        }
    }

    public function testFilenameContainsTimestampAndExtension(): void
    {
        $service = new ExportService();
        $this->assertMatchesRegularExpression('/^audit_logs_\d{4}-\d{2}-\d{2}_\d{6}\.csv$/', $service->filename('csv'));
        $this->assertMatchesRegularExpression('/\.json$/', $service->filename('json'));
        $this->assertMatchesRegularExpression('/\.ndjson$/', $service->filename('ndjson'));
    }

    public function testStreamFieldWhitelistRestrictsColumns(): void
    {
        $this->seedRows(1);

        $service = new ExportService();
        $query = $this->fetchTable('AuditStash.AuditLogs')->find();

        $stream = fopen('php://memory', 'r+');
        $service->stream($query, 'ndjson', $stream, ['type', 'source']);
        rewind($stream);
        $line = trim((string)stream_get_contents($stream));
        fclose($stream);

        $row = json_decode($line, true);
        $this->assertIsArray($row);
        // `id` is force-added so rows remain identifiable across exports.
        $this->assertSame(['id', 'type', 'source'], array_keys($row));
    }

    public function testEstimateCountsWithoutModifyingQuery(): void
    {
        $this->seedRows(5);

        $service = new ExportService();
        $query = $this->fetchTable('AuditStash.AuditLogs')->find();

        $count = $service->estimate($query);
        $this->assertSame(5, $count);

        // The estimate should not consume / mutate the query.
        $this->assertCount(5, $query->all()->toArray());
    }

    private function seedRows(int $count): void
    {
        $table = $this->fetchTable('AuditStash.AuditLogs');
        for ($i = 1; $i <= $count; $i++) {
            $table->save($table->newEntity([
                'transaction_key' => 'tx-' . $i,
                'type' => 'create',
                'source' => 'articles',
                'primary_key' => $i,
                'created' => new DateTime(),
            ]));
        }
    }
}
