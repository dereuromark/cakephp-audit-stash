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
}
