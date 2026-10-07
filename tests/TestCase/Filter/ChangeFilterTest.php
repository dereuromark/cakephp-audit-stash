<?php

declare(strict_types=1);

namespace AuditStash\Test\TestCase\Filter;

use AuditStash\Filter\ChangeFilter;
use Cake\TestSuite\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * ChangeFilter Test Case
 */
class ChangeFilterTest extends TestCase
{
    /**
     * Test filter returns changes when there are significant changes
     *
     * @return void
     */
    public function testFilterReturnsChanges(): void
    {
        $changed = ['title' => 'New Title', 'body' => 'New Body'];
        $original = ['title' => 'Old Title', 'body' => 'Old Body'];
        $config = [];

        $result = ChangeFilter::filter($changed, $original, $config);

        $this->assertNotNull($result);
        $this->assertSame($changed, $result['changed']);
        $this->assertSame($original, $result['original']);
    }

    /**
     * Test filter ignores empty changes
     *
     * @return void
     */
    public function testFilterIgnoresEmptyChanges(): void
    {
        $changed = ['title' => 'Same'];
        $original = ['title' => 'Same'];
        $config = ['ignoreEmpty' => true];

        $result = ChangeFilter::filter($changed, $original, $config);

        $this->assertNull($result);
    }

    /**
     * Test filter ignores timestamp-only changes
     *
     * @return void
     */
    public function testFilterIgnoresTimestampOnlyChanges(): void
    {
        $changed = ['modified' => '2024-01-02 10:00:00'];
        $original = ['modified' => '2024-01-01 10:00:00'];
        $config = ['ignoreTimestampOnly' => true];

        $result = ChangeFilter::filter($changed, $original, $config);

        $this->assertNull($result);
    }

    /**
     * Test filter does not ignore when non-timestamp fields change
     *
     * @return void
     */
    public function testFilterAllowsNonTimestampChanges(): void
    {
        $changed = ['modified' => '2024-01-02', 'title' => 'New'];
        $original = ['modified' => '2024-01-01', 'title' => 'Old'];
        $config = ['ignoreTimestampOnly' => true];

        $result = ChangeFilter::filter($changed, $original, $config);

        $this->assertNotNull($result);
        $this->assertArrayHasKey('title', $result['changed']);
    }

    /**
     * Test filter ignores specific fields only
     *
     * @return void
     */
    public function testFilterIgnoresSpecificFields(): void
    {
        $changed = ['last_seen' => '2024-01-02'];
        $original = ['last_seen' => '2024-01-01'];
        $config = ['ignoreFields' => ['last_seen']];

        $result = ChangeFilter::filter($changed, $original, $config);

        $this->assertNull($result);
    }

    /**
     * Test filter allows changes when other fields besides ignored fields change
     *
     * @return void
     */
    public function testFilterAllowsNonIgnoredFieldChanges(): void
    {
        $changed = ['last_seen' => '2024-01-02', 'status' => 'active'];
        $original = ['last_seen' => '2024-01-01', 'status' => 'pending'];
        $config = ['ignoreFields' => ['last_seen']];

        $result = ChangeFilter::filter($changed, $original, $config);

        $this->assertNotNull($result);
        $this->assertArrayHasKey('status', $result['changed']);
    }

    /**
     * Test filter ignores whitespace-only changes
     *
     * @return void
     */
    public function testFilterIgnoresWhitespaceOnlyChanges(): void
    {
        $changed = ['title' => 'Hello  World'];
        $original = ['title' => 'Hello World'];
        $config = ['ignoreWhitespace' => true];

        $result = ChangeFilter::filter($changed, $original, $config);

        $this->assertNull($result);
    }

    /**
     * Test filter ignores case-only changes
     *
     * @return void
     */
    public function testFilterIgnoresCaseOnlyChanges(): void
    {
        $changed = ['title' => 'HELLO'];
        $original = ['title' => 'hello'];
        $config = ['ignoreCase' => true];

        $result = ChangeFilter::filter($changed, $original, $config);

        $this->assertNull($result);
    }

    /**
     * Test isWhitespaceOnlyChange helper
     *
     * @return void
     */
    public function testIsWhitespaceOnlyChange(): void
    {
        $this->assertTrue(ChangeFilter::isWhitespaceOnlyChange('hello  world', 'hello world'));
        $this->assertTrue(ChangeFilter::isWhitespaceOnlyChange(' hello ', 'hello'));
        $this->assertFalse(ChangeFilter::isWhitespaceOnlyChange('hello', 'world'));
    }

    /**
     * Test isCaseOnlyChange helper
     *
     * @return void
     */
    public function testIsCaseOnlyChange(): void
    {
        $this->assertTrue(ChangeFilter::isCaseOnlyChange('Hello', 'hello'));
        $this->assertTrue(ChangeFilter::isCaseOnlyChange('HELLO', 'hello'));
        $this->assertFalse(ChangeFilter::isCaseOnlyChange('hello', 'world'));
    }

    /**
     * Test shouldIgnore helper method
     *
     * @return void
     */
    public function testShouldIgnore(): void
    {
        // Should ignore empty changes
        $this->assertTrue(ChangeFilter::shouldIgnore(
            ['title' => 'Same'],
            ['title' => 'Same'],
            ['ignoreEmpty' => true],
        ));

        // Should not ignore significant changes
        $this->assertFalse(ChangeFilter::shouldIgnore(
            ['title' => 'New'],
            ['title' => 'Old'],
            ['ignoreEmpty' => true],
        ));
    }

    /**
     * The ignore options decide whether a field counts as changed. They must
     * not alter the values that get logged.
     *
     * @return void
     */
    public function testNormalizationDoesNotAlterLoggedValues(): void
    {
        $changed = ['title' => '  Hello World ', 'code' => 'ABC'];
        $original = ['title' => 'Goodbye', 'code' => 'abc'];

        $result = ChangeFilter::filter($changed, $original, ['ignoreCase' => true, 'ignoreWhitespace' => true]);

        $this->assertSame(
            ['changed' => ['title' => '  Hello World '], 'original' => ['title' => 'Goodbye']],
            $result,
        );
    }

    /**
     * A value assigned in another scalar type is not a change: `'1'` over `1`.
     *
     * @param mixed $original Stored value
     * @param mixed $changed Assigned value
     * @param bool $isChange Whether an entry is expected
     *
     * @return void
     */
    #[DataProvider('typeOnlyDifferences')]
    public function testTypeOnlyDifferencesAreNotChanges(mixed $original, mixed $changed, bool $isChange): void
    {
        $result = ChangeFilter::filter(['field' => $changed], ['field' => $original], []);

        $this->assertSame($isChange, $result !== null);
    }

    /**
     * @return array<string, array<mixed>>
     */
    public static function typeOnlyDifferences(): array
    {
        return [
            'int as numeric string' => [1, '1', false],
            'float as numeric string' => [1.5, '1.5', false],
            'different number' => [1, '2', true],
            'floats differing past string precision' => [1.000000000000001, 1.000000000000002, true],
            'float as differently written string' => [1.5, '1.50', false],
            'int as float' => [1, 1.0, false],
            'int as padded string' => [1, '01', true],
            'non-numeric string over number' => [1, 'one', true],
            'null to empty string' => [null, '', true],
            'zero to empty string' => [0, '', true],
            'bool to int' => [true, 1, true],
            'int to bool' => [0, false, true],
            'string to other string' => ['a', 'b', true],
        ];
    }
}
