<?php

declare(strict_types=1);

namespace AuditStash\Test\TestCase\View\Helper;

use AuditStash\View\Helper\AuditHelper;
use Cake\TestSuite\TestCase;
use Cake\View\View;

/**
 * AuditStash\View\Helper\AuditHelper Test Case
 */
class AuditHelperTest extends TestCase
{
    /**
     * Test subject
     *
     * @var \AuditStash\View\Helper\AuditHelper
     */
    protected $Audit;

    /**
     * setUp method
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $view = new View();
        $this->Audit = new AuditHelper($view);
    }

    /**
     * tearDown method
     *
     * @return void
     */
    protected function tearDown(): void
    {
        unset($this->Audit);

        parent::tearDown();
    }

    /**
     * Test diff method with simple changes
     *
     * @return void
     */
    public function testDiffSimpleChanges(): void
    {
        $original = json_encode(['name' => 'John', 'age' => 30]);
        $changed = json_encode(['name' => 'Jane', 'age' => 31]);

        $result = $this->Audit->diff($original, $changed);

        $this->assertStringContainsString('name', $result);
        $this->assertStringContainsString('John', $result);
        $this->assertStringContainsString('Jane', $result);
        $this->assertStringContainsString('age', $result);
        $this->assertStringContainsString('30', $result);
        $this->assertStringContainsString('31', $result);
    }

    /**
     * Test diff method with no changes
     *
     * @return void
     */
    public function testDiffNoChanges(): void
    {
        $original = json_encode(['name' => 'John']);
        $changed = json_encode(['name' => 'John']);

        $result = $this->Audit->diff($original, $changed);

        $this->assertStringContainsString('No changes', $result);
    }

    /**
     * Test diff method with null values
     *
     * @return void
     */
    public function testDiffWithNulls(): void
    {
        $original = json_encode(['name' => 'John', 'email' => null]);
        $changed = json_encode(['name' => 'John', 'email' => 'john@example.com']);

        $result = $this->Audit->diff($original, $changed);

        $this->assertStringContainsString('email', $result);
        $this->assertStringContainsString('null', $result);
        $this->assertStringContainsString('john@example.com', $result);
    }

    /**
     * Test eventTypeBadge method
     *
     * @return void
     */
    public function testEventTypeBadge(): void
    {
        $result = $this->Audit->eventTypeBadge('create');
        $this->assertStringContainsString('badge', $result);
        $this->assertStringContainsString('Create', $result);
        $this->assertStringContainsString('bg-success', $result);

        $result = $this->Audit->eventTypeBadge('update');
        $this->assertStringContainsString('badge', $result);
        $this->assertStringContainsString('Update', $result);
        $this->assertStringContainsString('bg-primary', $result);

        $result = $this->Audit->eventTypeBadge('delete');
        $this->assertStringContainsString('badge', $result);
        $this->assertStringContainsString('Delete', $result);
        $this->assertStringContainsString('bg-danger', $result);
    }

    /**
     * Test transactionId method
     *
     * @return void
     */
    public function testTransactionId(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440000';

        $result = $this->Audit->transactionId($uuid, false);
        $this->assertStringContainsString('code', $result);
        $this->assertStringContainsString('550e8400', $result);
        $this->assertStringNotContainsString($uuid, $result);

        $result = $this->Audit->transactionId($uuid, true);
        $this->assertStringContainsString('code', $result);
        $this->assertStringContainsString($uuid, $result);
    }

    /**
     * Test changeSummary method
     *
     * @return void
     */
    public function testChangeSummary(): void
    {
        $changed = json_encode(['name' => 'John', 'age' => 30]);
        $result = $this->Audit->changeSummary($changed);

        $this->assertStringContainsString('2 field(s)', $result);
        $this->assertStringContainsString('name', $result);
        $this->assertStringContainsString('age', $result);
    }

    /**
     * Test changeSummary with many fields
     *
     * @return void
     */
    public function testChangeSummaryManyFields(): void
    {
        $changed = json_encode([
            'field1' => 'value1',
            'field2' => 'value2',
            'field3' => 'value3',
            'field4' => 'value4',
            'field5' => 'value5',
        ]);
        $result = $this->Audit->changeSummary($changed);

        $this->assertStringContainsString('5 field(s)', $result);
        $this->assertStringContainsString('...', $result);
    }

    /**
     * Test changeSummary with no changes
     *
     * @return void
     */
    public function testChangeSummaryNoChanges(): void
    {
        $result = $this->Audit->changeSummary(null);
        $this->assertSame('No changes', $result);

        $result = $this->Audit->changeSummary('[]');
        $this->assertSame('No changes', $result);
    }

    /**
     * Test formatValue with different types
     *
     * @return void
     */
    public function testFormatValue(): void
    {
        // Null
        $result = $this->Audit->formatValue(null);
        $this->assertStringContainsString('null', $result);

        // Boolean
        $result = $this->Audit->formatValue(true);
        $this->assertStringContainsString('true', $result);
        $this->assertStringContainsString('badge', $result);

        $result = $this->Audit->formatValue(false);
        $this->assertStringContainsString('false', $result);
        $this->assertStringContainsString('badge', $result);

        // Array
        $result = $this->Audit->formatValue(['key' => 'value']);
        $this->assertStringContainsString('key', $result);
        $this->assertStringContainsString('value', $result);
        $this->assertStringContainsString('code', $result);

        // String
        $result = $this->Audit->formatValue('test');
        $this->assertSame('test', $result);
    }

    /**
     * Test formatValue with long string
     *
     * @return void
     */
    public function testFormatValueLongString(): void
    {
        $longString = str_repeat('a', 150);
        $result = $this->Audit->formatValue($longString);

        $this->assertStringContainsString('text-break', $result);
        $this->assertStringContainsString($longString, $result);
    }

    /**
     * Test diffInline method with simple changes
     *
     * @return void
     */
    public function testDiffInlineSimpleChanges(): void
    {
        $original = json_encode(['name' => 'John', 'age' => 30]);
        $changed = json_encode(['name' => 'Jane', 'age' => 31]);

        $result = $this->Audit->diffInline($original, $changed);

        $this->assertStringContainsString('audit-diff-inline', $result);
        $this->assertStringContainsString('name', $result);
        $this->assertStringContainsString('John', $result);
        $this->assertStringContainsString('Jane', $result);
        $this->assertStringContainsString('age', $result);
        $this->assertStringContainsString('30', $result);
        $this->assertStringContainsString('31', $result);
        $this->assertStringContainsString('−', $result); // minus sign for removed
        $this->assertStringContainsString('+', $result); // plus sign for added
    }

    /**
     * Test diffInline method with no changes
     *
     * @return void
     */
    public function testDiffInlineNoChanges(): void
    {
        $original = json_encode(['name' => 'John']);
        $changed = json_encode(['name' => 'John']);

        $result = $this->Audit->diffInline($original, $changed);

        $this->assertStringContainsString('No changes', $result);
    }

    /**
     * Test diffInline method with null to value change
     *
     * @return void
     */
    public function testDiffInlineNullToValue(): void
    {
        $original = json_encode(['email' => null]);
        $changed = json_encode(['email' => 'john@example.com']);

        $result = $this->Audit->diffInline($original, $changed);

        $this->assertStringContainsString('email', $result);
        $this->assertStringContainsString('null', $result);
        $this->assertStringContainsString('john@example.com', $result);
        $this->assertStringContainsString('text-danger', $result); // removed color
        $this->assertStringContainsString('text-success', $result); // added color
    }

    /**
     * Test diffInline method with value removal
     *
     * @return void
     */
    public function testDiffInlineValueRemoval(): void
    {
        $original = json_encode(['name' => 'John', 'temp' => 'value']);
        $changed = json_encode(['name' => 'John']);

        $result = $this->Audit->diffInline($original, $changed);

        $this->assertStringContainsString('temp', $result);
        $this->assertStringContainsString('value', $result);
        $this->assertStringContainsString('bg-danger', $result); // background for removed
    }

    /**
     * Test eventTypeBadge method with revert type
     *
     * @return void
     */
    public function testEventTypeBadgeRevert(): void
    {
        $result = $this->Audit->eventTypeBadge('revert');

        $this->assertStringContainsString('badge', $result);
        $this->assertStringContainsString('bg-warning', $result);
        $this->assertStringContainsString('Revert', $result);
    }

    /**
     * Test metadata method with valid JSON
     *
     * @return void
     */
    public function testMetadataWithValidJson(): void
    {
        $meta = json_encode(['ip' => '127.0.0.1', 'user_agent' => 'Mozilla/5.0']);
        $result = $this->Audit->metadata($meta);

        $this->assertStringContainsString('table', $result);
        $this->assertStringContainsString('ip', $result);
        $this->assertStringContainsString('127.0.0.1', $result);
        $this->assertStringContainsString('user_agent', $result);
        $this->assertStringContainsString('Mozilla/5.0', $result);
    }

    /**
     * Test metadata method with null
     *
     * @return void
     */
    public function testMetadataWithNull(): void
    {
        $result = $this->Audit->metadata(null);

        $this->assertStringContainsString('No metadata available', $result);
    }

    /**
     * Test metadata method with invalid JSON
     *
     * @return void
     */
    public function testMetadataWithInvalidJson(): void
    {
        $result = $this->Audit->metadata('invalid json');

        $this->assertStringContainsString('No metadata available', $result);
    }

    /**
     * Test fieldValuesTable method with valid JSON
     *
     * @return void
     */
    public function testFieldValuesTableWithValidJson(): void
    {
        $data = json_encode(['name' => 'John', 'email' => 'john@example.com']);
        $result = $this->Audit->fieldValuesTable($data, 'Created with values:');

        $this->assertStringContainsString('table', $result);
        $this->assertStringContainsString('Created with values:', $result);
        $this->assertStringContainsString(__('Field'), $result);
        $this->assertStringContainsString(__('Value'), $result);
        $this->assertStringContainsString('name', $result);
        $this->assertStringContainsString('John', $result);
        $this->assertStringContainsString('email', $result);
        $this->assertStringContainsString('john@example.com', $result);
    }

    /**
     * Test fieldValuesTable method without title
     *
     * @return void
     */
    public function testFieldValuesTableWithoutTitle(): void
    {
        $data = json_encode(['name' => 'John']);
        $result = $this->Audit->fieldValuesTable($data);

        $this->assertStringContainsString('table', $result);
        $this->assertStringNotContainsString('<h6>', $result);
        $this->assertStringContainsString('name', $result);
        $this->assertStringContainsString('John', $result);
    }

    /**
     * Test fieldValuesTable method with null
     *
     * @return void
     */
    public function testFieldValuesTableWithNull(): void
    {
        $result = $this->Audit->fieldValuesTable(null);

        $this->assertStringContainsString('No data available', $result);
    }

    /**
     * Test diffStyles method
     *
     * @return void
     */
    public function testDiffStyles(): void
    {
        $result = $this->Audit->diffStyles();

        $this->assertStringContainsString('<style>', $result);
        $this->assertStringContainsString('.audit-diff', $result);
        $this->assertStringContainsString('.diff-wrapper', $result);
        // jfcherng/php-diff uses .diff-wrapper.diff classes
        $this->assertStringContainsString('.diff-wrapper.diff', $result);
        $this->assertStringContainsString('</style>', $result);
    }

    /**
     * Test diffScript method
     *
     * @return void
     */
    public function testDiffScript(): void
    {
        $result = $this->Audit->diffScript();

        $this->assertStringContainsString('<script>', $result);
        $this->assertStringContainsString('btn-inline-diff', $result);
        $this->assertStringContainsString('btn-side-diff', $result);
        $this->assertStringContainsString('addEventListener', $result);
        $this->assertStringContainsString('</script>', $result);
    }
}
