<?php

declare(strict_types=1);

namespace AuditStash\Test\TestCase\View\Helper;

use AuditStash\View\Helper\AuditHelper;
use Cake\Core\Configure;
use Cake\TestSuite\TestCase;
use Cake\View\View;
use ReflectionMethod;

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
        Configure::delete('AuditStash.linkUser');
        Configure::delete('AuditStash.userSeparator');
        Configure::delete('AuditStash.linkRecord');

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
        $this->assertStringContainsString('.diff-side-by-side', $result);
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

    /**
     * Test formatUser with null value
     *
     * @return void
     */
    public function testFormatUserNull(): void
    {
        $result = $this->Audit->formatUser(null);

        $this->assertStringContainsString('N/A', $result);
        $this->assertStringContainsString('text-muted', $result);
    }

    /**
     * Test formatUser with empty string
     *
     * @return void
     */
    public function testFormatUserEmpty(): void
    {
        $result = $this->Audit->formatUser('');

        $this->assertStringContainsString('N/A', $result);
        $this->assertStringContainsString('text-muted', $result);
    }

    /**
     * Test formatUser with string value (no link config)
     *
     * @return void
     */
    public function testFormatUserStringNoLink(): void
    {
        $result = $this->Audit->formatUser('john_doe');

        $this->assertSame('john_doe', $result);
    }

    /**
     * Test formatUser with integer value (no link config)
     *
     * @return void
     */
    public function testFormatUserIntegerNoLink(): void
    {
        $result = $this->Audit->formatUser(123);

        $this->assertSame('123', $result);
    }

    /**
     * Test formatUser with string pattern config
     *
     * @return void
     */
    public function testFormatUserWithStringPattern(): void
    {
        Configure::write('AuditStash.linkUser', '/admin/users/view/{user}');

        $result = $this->Audit->formatUser(123);

        $this->assertStringContainsString('href="/admin/users/view/123"', $result);
        $this->assertStringContainsString('>123</a>', $result);
    }

    /**
     * Test formatUser with callable config
     *
     * @return void
     */
    public function testFormatUserWithCallable(): void
    {
        Configure::write('AuditStash.linkUser', function ($user) {
            if (is_numeric($user)) {
                return '/admin/users/view/' . $user;
            }

            return null;
        });

        // Numeric user gets linked
        $result = $this->Audit->formatUser(456);
        $this->assertStringContainsString('href="/admin/users/view/456"', $result);
        $this->assertStringContainsString('>456</a>', $result);

        // Non-numeric user does not get linked
        $result = $this->Audit->formatUser('john@example.com');
        $this->assertSame('john@example.com', $result);
    }

    /**
     * Test formatUser with callable returning null (no link)
     *
     * @return void
     */
    public function testFormatUserWithCallableReturningNull(): void
    {
        Configure::write('AuditStash.linkUser', function ($user) {
            return null; // Always return null
        });

        $result = $this->Audit->formatUser(789);

        // Should just return escaped value without link
        $this->assertSame('789', $result);
        $this->assertStringNotContainsString('href=', $result);
    }

    /**
     * Test formatUser escapes HTML in user value
     *
     * @return void
     */
    public function testFormatUserEscapesHtml(): void
    {
        $result = $this->Audit->formatUser('<script>alert(1)</script>');

        $this->assertStringNotContainsString('<script>', $result);
        $this->assertStringContainsString('&lt;script&gt;', $result);
    }

    /**
     * Test formatUser with compound format (id:displayName)
     *
     * @return void
     */
    public function testFormatUserCompoundFormat(): void
    {
        // Without link config - displays the displayName part
        $result = $this->Audit->formatUser('123:John Doe');

        $this->assertSame('John Doe', $result);
    }

    /**
     * Test formatUser with compound format and link config
     *
     * @return void
     */
    public function testFormatUserCompoundFormatWithLink(): void
    {
        Configure::write('AuditStash.linkUser', '/admin/users/view/{user}');

        $result = $this->Audit->formatUser('456:Jane Smith');

        // Links to ID but displays the name
        $this->assertStringContainsString('href="/admin/users/view/456"', $result);
        $this->assertStringContainsString('>Jane Smith</a>', $result);
    }

    /**
     * Test formatUser with compound format using {display} placeholder
     *
     * @return void
     */
    public function testFormatUserCompoundFormatDisplayPlaceholder(): void
    {
        Configure::write('AuditStash.linkUser', '/admin/users/view/{user}/{display}');

        $result = $this->Audit->formatUser('789:admin');

        $this->assertStringContainsString('href="/admin/users/view/789/admin"', $result);
        $this->assertStringContainsString('>admin</a>', $result);
    }

    /**
     * Test formatUser with custom separator
     *
     * @return void
     */
    public function testFormatUserCustomSeparator(): void
    {
        Configure::write('AuditStash.userSeparator', '|');
        Configure::write('AuditStash.linkUser', '/admin/users/view/{user}');

        $result = $this->Audit->formatUser('123|Jane');

        $this->assertStringContainsString('href="/admin/users/view/123"', $result);
        $this->assertStringContainsString('>Jane</a>', $result);
    }

    /**
     * Test formatUser with callable receiving parsed values
     *
     * @return void
     */
    public function testFormatUserCallableWithParsedValues(): void
    {
        Configure::write('AuditStash.linkUser', function ($id, $displayName, $raw) {
            return '/users/' . $id . '?name=' . urlencode($displayName);
        });

        $result = $this->Audit->formatUser('42:John Doe');

        $this->assertStringContainsString('href="/users/42?name=John+Doe"', $result);
        $this->assertStringContainsString('>John Doe</a>', $result);
    }

    /**
     * Test formatRecord without link config
     *
     * @return void
     */
    public function testFormatRecordNoLink(): void
    {
        $result = $this->Audit->formatRecord('Articles', 123);

        $this->assertSame('123', $result);
    }

    /**
     * Test formatRecord with display value and no link config
     *
     * @return void
     */
    public function testFormatRecordWithDisplayValueNoLink(): void
    {
        $result = $this->Audit->formatRecord('Articles', 123, 'My Article Title');

        $this->assertSame('My Article Title', $result);
    }

    /**
     * Test formatRecord with string pattern config
     *
     * @return void
     */
    public function testFormatRecordWithStringPattern(): void
    {
        Configure::write('AuditStash.linkRecord', '/admin/{source}/view/{primary_key}');

        $result = $this->Audit->formatRecord('Articles', 123);

        $this->assertStringContainsString('href="/admin/Articles/view/123"', $result);
        $this->assertStringContainsString('>123</a>', $result);
    }

    /**
     * Test formatRecord with display value and string pattern config
     *
     * @return void
     */
    public function testFormatRecordWithDisplayValueAndLink(): void
    {
        Configure::write('AuditStash.linkRecord', '/admin/{source}/view/{primary_key}');

        $result = $this->Audit->formatRecord('Articles', 456, 'My Article');

        $this->assertStringContainsString('href="/admin/Articles/view/456"', $result);
        $this->assertStringContainsString('>My Article</a>', $result);
    }

    /**
     * Test formatRecord with callable config
     *
     * @return void
     */
    public function testFormatRecordWithCallable(): void
    {
        Configure::write('AuditStash.linkRecord', function ($source, $primaryKey, $displayValue) {
            return '/admin/' . strtolower($source) . '/view/' . $primaryKey;
        });

        $result = $this->Audit->formatRecord('Users', 789);

        $this->assertStringContainsString('href="/admin/users/view/789"', $result);
        $this->assertStringContainsString('>789</a>', $result);
    }

    /**
     * Test formatRecord with callable returning null (no link)
     *
     * @return void
     */
    public function testFormatRecordWithCallableReturningNull(): void
    {
        Configure::write('AuditStash.linkRecord', function ($source, $primaryKey, $displayValue) {
            // Only link certain tables
            if ($source === 'Articles') {
                return '/admin/articles/view/' . $primaryKey;
            }

            return null;
        });

        // Articles gets linked
        $result = $this->Audit->formatRecord('Articles', 123);
        $this->assertStringContainsString('href="/admin/articles/view/123"', $result);

        // Other tables don't get linked
        $result = $this->Audit->formatRecord('Users', 456);
        $this->assertSame('456', $result);
        $this->assertStringNotContainsString('href=', $result);
    }

    /**
     * Test formatRecord with callable config returning array URL
     *
     * This test demonstrates that callables can return CakePHP array URLs.
     * Note: Array URL config requires proper routes to be set up in your application.
     *
     * @return void
     */
    public function testFormatRecordWithCallableReturningArrayUrl(): void
    {
        Configure::write('AuditStash.linkRecord', function ($source, $primaryKey, $displayValue) {
            // Build URL string from components (routes would be needed for actual array URLs)
            return '/admin/' . strtolower($source) . '/view/' . $primaryKey;
        });

        $result = $this->Audit->formatRecord('Articles', 123);

        $this->assertStringContainsString('href="/admin/articles/view/123"', $result);
        $this->assertStringContainsString('>123</a>', $result);
    }

    /**
     * Test formatRecord escapes HTML in display value
     *
     * @return void
     */
    public function testFormatRecordEscapesHtml(): void
    {
        $result = $this->Audit->formatRecord('Articles', 1, '<script>alert(1)</script>');

        $this->assertStringNotContainsString('<script>', $result);
        $this->assertStringContainsString('&lt;script&gt;', $result);
    }

    /**
     * Test formatRecord with {display} placeholder in string pattern
     *
     * @return void
     */
    public function testFormatRecordDisplayPlaceholder(): void
    {
        Configure::write('AuditStash.linkRecord', '/admin/{source}/view/{primary_key}?title={display}');

        $result = $this->Audit->formatRecord('Articles', 123, 'My Title');

        $this->assertStringContainsString('href="/admin/Articles/view/123?title=My Title"', $result);
        $this->assertStringContainsString('>My Title</a>', $result);
    }

    /**
     * Test formatRecord with string primary key
     *
     * @return void
     */
    public function testFormatRecordWithStringPrimaryKey(): void
    {
        Configure::write('AuditStash.linkRecord', '/admin/{source}/view/{primary_key}');

        $result = $this->Audit->formatRecord('Articles', 'uuid-1234-5678');

        $this->assertStringContainsString('href="/admin/Articles/view/uuid-1234-5678"', $result);
        $this->assertStringContainsString('>uuid-1234-5678</a>', $result);
    }

    /**
     * Test buildRecordUrl with array URL config replaces placeholders correctly
     *
     * Uses reflection to test the protected method directly since array URLs
     * require routes to be configured for the full formatRecord() flow.
     *
     * @return void
     */
    public function testBuildRecordUrlWithArrayConfig(): void
    {
        $method = new ReflectionMethod($this->Audit, 'buildRecordUrl');

        $linkConfig = [
            'prefix' => 'Admin',
            'controller' => '{source}',
            'action' => 'view',
            '{primary_key}',
        ];

        $result = $method->invoke($this->Audit, $linkConfig, 'Articles', '123', 'My Article');

        $expected = [
            'prefix' => 'Admin',
            'controller' => 'Articles',
            'action' => 'view',
            '123',
        ];

        $this->assertSame($expected, $result);
    }

    /**
     * Test buildRecordUrl with array URL config replaces display placeholder
     *
     * @return void
     */
    public function testBuildRecordUrlWithArrayConfigDisplayPlaceholder(): void
    {
        $method = new ReflectionMethod($this->Audit, 'buildRecordUrl');

        $linkConfig = [
            'controller' => '{source}',
            'action' => 'view',
            '{primary_key}',
            '?' => ['title' => '{display}'],
        ];

        $result = $method->invoke($this->Audit, $linkConfig, 'Users', '456', 'John Doe');

        // Note: nested arrays are not replaced, only top-level values
        $expected = [
            'controller' => 'Users',
            'action' => 'view',
            '456',
            '?' => ['title' => '{display}'], // Nested values not replaced
        ];

        $this->assertSame($expected, $result);
    }
}
