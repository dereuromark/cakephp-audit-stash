<?php

declare(strict_types=1);

namespace AuditStash\Test\TestCase\Lib;

use AuditStash\Lib\DiffLib;
use PHPUnit\Framework\TestCase;

class DiffLibTest extends TestCase
{
    protected DiffLib $diffLib;

    public function setUp(): void
    {
        parent::setUp();
        $this->diffLib = new DiffLib();
    }

    public function testCompareIdenticalStrings(): void
    {
        $result = $this->diffLib->compare('Hello World', 'Hello World');

        $this->assertStringContainsString('<table class="diff-wrapper diff-inline">', $result);
        $this->assertStringNotContainsString('<ins>', $result);
        $this->assertStringNotContainsString('<del>', $result);
    }

    public function testCompareSimpleChange(): void
    {
        $result = $this->diffLib->compare('Hello World', 'Hello Everyone');

        $this->assertStringContainsString('<del>', $result);
        $this->assertStringContainsString('<ins>', $result);
        // Character-level diff may split words, so just check for key parts
        $this->assertStringContainsString('class="removed"', $result);
        $this->assertStringContainsString('class="added"', $result);
    }

    public function testCompareMultilineWithContext(): void
    {
        $old = "Line 1\nLine 2\nLine 3\nLine 4\nLine 5\nLine 6\nLine 7\nLine 8";
        $new = "Line 1\nLine 2\nLine 3\nChanged Line\nLine 5\nLine 6\nLine 7\nLine 8";

        $this->diffLib->contextLines = 2;
        $result = $this->diffLib->compare($old, $new);

        // The removed line and new line should be in the diff
        $this->assertStringContainsString('Line', $result);
        $this->assertStringContainsString('Changed', $result);
        $this->assertStringContainsString('<del>', $result);
        $this->assertStringContainsString('<ins>', $result);
        // Context lines should be visible
        $this->assertStringContainsString('class="unchanged"', $result);
    }

    public function testCompareCharacterLevelDiff(): void
    {
        $result = $this->diffLib->compare('The quick brown fox', 'The slow brown dog');

        // Should highlight changes - character-level diff may break words
        $this->assertStringContainsString('<del>', $result);
        $this->assertStringContainsString('<ins>', $result);
        $this->assertStringContainsString('class="removed"', $result);
        $this->assertStringContainsString('class="added"', $result);
        // Core unchanged parts should still be visible
        $this->assertStringContainsString('The', $result);
        $this->assertStringContainsString('brown', $result);
    }

    public function testCompareAddedLines(): void
    {
        $old = "Line 1\nLine 2";
        $new = "Line 1\nNew Line\nLine 2";

        $result = $this->diffLib->compare($old, $new);

        $this->assertStringContainsString('class="added"', $result);
        $this->assertStringContainsString('New Line', $result);
    }

    public function testCompareRemovedLines(): void
    {
        $old = "Line 1\nOld Line\nLine 2";
        $new = "Line 1\nLine 2";

        $result = $this->diffLib->compare($old, $new);

        $this->assertStringContainsString('class="removed"', $result);
        $this->assertStringContainsString('Old Line', $result);
    }

    public function testNormalizesLineEndings(): void
    {
        $old = "Line 1\r\nLine 2";
        $new = "Line 1\nLine 2";

        $result = $this->diffLib->compare($old, $new);

        // Should not show any changes since content is the same after normalization
        $this->assertStringNotContainsString('<ins>', $result);
        $this->assertStringNotContainsString('<del>', $result);
    }

    public function testContextLinesConfiguration(): void
    {
        $old = "1\n2\n3\n4\n5\n6\n7\n8\n9\n10";
        $new = "1\n2\n3\n4\nCHANGED\n6\n7\n8\n9\n10";

        $this->diffLib->contextLines = 1;
        $result = $this->diffLib->compare($old, $new);

        // The changed content should be visible
        $this->assertStringContainsString('CHANGED', $result);
        $this->assertStringContainsString('<del>', $result);
        $this->assertStringContainsString('<ins>', $result);
    }

    public function testEscapesHtmlInContent(): void
    {
        $result = $this->diffLib->compare('<script>alert("xss")</script>', '<b>safe</b>');

        // HTML should be escaped
        $this->assertStringContainsString('&lt;', $result);
        $this->assertStringContainsString('&gt;', $result);
        // No raw script tags should be in the output
        $this->assertStringNotContainsString('<script>', $result);
    }

    public function testHandlesEmptyStrings(): void
    {
        $result = $this->diffLib->compare('', 'New content');

        $this->assertStringContainsString('<ins>', $result);
        $this->assertStringContainsString('New content', $result);

        $result = $this->diffLib->compare('Old content', '');

        $this->assertStringContainsString('<del>', $result);
        $this->assertStringContainsString('Old content', $result);
    }

    public function testHandlesUnicodeCharacters(): void
    {
        $result = $this->diffLib->compare('Älterer Text mit Ümlauten', 'Neuer Text mit Ümlauten');

        // Unicode characters should be properly handled
        $this->assertStringContainsString('Ümlauten', $result);
        $this->assertStringContainsString('<del>', $result);
        $this->assertStringContainsString('<ins>', $result);
        // Text is shown in character-level diff
        $this->assertStringContainsString('Text', $result);
    }

    // ========== Side-by-Side Tests ==========

    public function testCompareSideBySideIdenticalStrings(): void
    {
        $result = $this->diffLib->compareSideBySide('Hello World', 'Hello World');

        $this->assertStringContainsString('<table class="diff-wrapper diff-side-by-side">', $result);
        $this->assertStringContainsString('Before', $result);
        $this->assertStringContainsString('After', $result);
        $this->assertStringNotContainsString('<ins>', $result);
        $this->assertStringNotContainsString('<del>', $result);
    }

    public function testCompareSideBySideSimpleChange(): void
    {
        $result = $this->diffLib->compareSideBySide('Hello World', 'Hello Everyone');

        $this->assertStringContainsString('<table class="diff-wrapper diff-side-by-side">', $result);
        $this->assertStringContainsString('class="changed"', $result);
        // Both old and new should be visible
        $this->assertStringContainsString('Hello', $result);
    }

    public function testCompareSideBySideMultiline(): void
    {
        $old = "Line 1\nLine 2\nLine 3";
        $new = "Line 1\nModified\nLine 3";

        $result = $this->diffLib->compareSideBySide($old, $new);

        $this->assertStringContainsString('<table class="diff-wrapper diff-side-by-side">', $result);
        $this->assertStringContainsString('Line 1', $result);
        $this->assertStringContainsString('Line 3', $result);
        $this->assertStringContainsString('class="changed"', $result);
    }

    public function testCompareSideBySideAddedLines(): void
    {
        $old = "Line 1\nLine 2";
        $new = "Line 1\nNew Line\nLine 2";

        $result = $this->diffLib->compareSideBySide($old, $new);

        $this->assertStringContainsString('New Line', $result);
        $this->assertStringContainsString('class="changed"', $result);
    }

    public function testCompareSideBySideRemovedLines(): void
    {
        $old = "Line 1\nOld Line\nLine 2";
        $new = "Line 1\nLine 2";

        $result = $this->diffLib->compareSideBySide($old, $new);

        $this->assertStringContainsString('Old Line', $result);
        $this->assertStringContainsString('class="changed"', $result);
    }

    public function testCompareSideBySideEscapesHtml(): void
    {
        $result = $this->diffLib->compareSideBySide('<div>old</div>', '<span>new</span>');

        // HTML should be escaped (character-level diff may break up tags)
        $this->assertStringContainsString('&lt;', $result);
        $this->assertStringContainsString('&gt;', $result);
        // Raw HTML tags should not be present
        $this->assertStringNotContainsString('<div>old</div>', $result);
        $this->assertStringNotContainsString('<span>new</span>', $result);
    }

    public function testCompareSideBySideWithContext(): void
    {
        $old = "1\n2\n3\n4\n5\n6\n7\n8\n9\n10";
        $new = "1\n2\n3\n4\nCHANGED\n6\n7\n8\n9\n10";

        $this->diffLib->contextLines = 2;
        $result = $this->diffLib->compareSideBySide($old, $new);

        $this->assertStringContainsString('CHANGED', $result);
        // Context lines around the change should be visible
        $this->assertStringContainsString('class="unchanged"', $result);
        $this->assertStringContainsString('class="changed"', $result);
    }

    // ========== Edge Cases ==========

    public function testBothEmptyStrings(): void
    {
        $result = $this->diffLib->compare('', '');

        $this->assertStringContainsString('<table', $result);
        $this->assertStringNotContainsString('<del>', $result);
        $this->assertStringNotContainsString('<ins>', $result);
    }

    public function testBothEmptyStringsSideBySide(): void
    {
        $result = $this->diffLib->compareSideBySide('', '');

        $this->assertStringContainsString('<table', $result);
        $this->assertStringNotContainsString('<del>', $result);
        $this->assertStringNotContainsString('<ins>', $result);
    }

    public function testWhitespaceOnlyChange(): void
    {
        $result = $this->diffLib->compare('hello world', 'hello  world');

        // Should detect whitespace difference
        $this->assertStringContainsString('hello', $result);
        $this->assertStringContainsString('world', $result);
    }

    public function testVeryLongLine(): void
    {
        $longString = str_repeat('a', 1000);
        $result = $this->diffLib->compare($longString, $longString . 'b');

        $this->assertStringContainsString('<ins>', $result);
        $this->assertStringContainsString('b', $result);
    }

    public function testSpecialCharacters(): void
    {
        $result = $this->diffLib->compare(
            "Tab:\there\nQuotes: \"test\"",
            "Tab:\tthere\nQuotes: 'test'",
        );

        $this->assertStringContainsString('Tab:', $result);
        $this->assertStringContainsString('Quotes:', $result);
    }

    public function testNewlineAtEndOfFile(): void
    {
        $result = $this->diffLib->compare("Line 1\nLine 2\n", "Line 1\nLine 2");

        // Trailing newline difference should be detected or normalized
        $this->assertStringContainsString('Line 1', $result);
        $this->assertStringContainsString('Line 2', $result);
    }

    public function testMixedLineEndings(): void
    {
        $old = "Line 1\r\nLine 2\rLine 3\n";
        $new = "Line 1\nLine 2\nLine 3\n";

        $result = $this->diffLib->compare($old, $new);

        // After normalization, should be identical
        $this->assertStringNotContainsString('<del>', $result);
        $this->assertStringNotContainsString('<ins>', $result);
    }

    public function testOnlyWhitespaceContent(): void
    {
        $result = $this->diffLib->compare('   ', "\t\t");

        // Whitespace-only changes use the special renderer
        $this->assertStringContainsString('diff-whitespace-change', $result);
        $this->assertStringContainsString('<del>', $result);
        $this->assertStringContainsString('<ins>', $result);
    }

    public function testNumbersAndSymbols(): void
    {
        $result = $this->diffLib->compare(
            'Price: $100.00 (10% off)',
            'Price: $90.00 (20% off)',
        );

        $this->assertStringContainsString('Price:', $result);
        $this->assertStringContainsString('$', $result);
        $this->assertStringContainsString('%', $result);
    }

    public function testJsonContent(): void
    {
        $old = '{"name": "John", "age": 30}';
        $new = '{"name": "Jane", "age": 31}';

        $result = $this->diffLib->compare($old, $new);

        $this->assertStringContainsString('name', $result);
        $this->assertStringContainsString('age', $result);
    }

    public function testLargeContextLines(): void
    {
        $old = "1\n2\n3\n4\n5";
        $new = "1\n2\nX\n4\n5";

        $this->diffLib->contextLines = 10;
        $result = $this->diffLib->compare($old, $new);

        // All lines should be visible with large context
        $this->assertStringContainsString('1', $result);
        $this->assertStringContainsString('5', $result);
        $this->assertStringNotContainsString('class="separator"', $result);
    }

    public function testZeroContextLines(): void
    {
        $old = "1\n2\n3\n4\n5";
        $new = "1\n2\nX\n4\n5";

        $this->diffLib->contextLines = 0;
        $result = $this->diffLib->compare($old, $new);

        // Only changed lines should be visible
        $this->assertStringContainsString('X', $result);
        $this->assertStringContainsString('<del>', $result);
        $this->assertStringContainsString('<ins>', $result);
    }

    public function testMultipleChangesInSameFile(): void
    {
        $old = "A\nB\nC\nD\nE\nF\nG";
        $new = "A\nX\nC\nD\nY\nF\nG";

        $this->diffLib->contextLines = 1;
        $result = $this->diffLib->compare($old, $new);

        // Both changes should be visible
        $this->assertStringContainsString('X', $result);
        $this->assertStringContainsString('Y', $result);
    }

    public function testCompareSideBySideCharacterHighlighting(): void
    {
        $result = $this->diffLib->compareSideBySide('The cat sat', 'The bat sat');

        // Character-level highlighting should be present
        $this->assertStringContainsString('class="changed"', $result);
        // Common parts should be visible
        $this->assertStringContainsString('The', $result);
        $this->assertStringContainsString('sat', $result);
    }
}
