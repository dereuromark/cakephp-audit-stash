<?php

declare(strict_types=1);

namespace AuditStash\Lib;

use SebastianBergmann\Diff\Differ;
use SebastianBergmann\Diff\Output\UnifiedDiffOutputBuilder;

/**
 * A library for generating HTML diffs between two strings.
 *
 * Uses sebastian/diff for the underlying diff algorithm.
 */
class DiffLib
{
 /**
  * Number of context lines to show around changes.
  *
  * @var int
  */
    public int $contextLines = 3;

    /**
     * Compare two strings and return an inline HTML diff.
     *
     * @param string $old Original text
     * @param string $new Changed text
     *
     * @return string HTML diff output
     */
    public function compare(string $old, string $new): string
    {
        // Normalize line endings
        $old = str_replace(["\r\n", "\r"], "\n", $old);
        $new = str_replace(["\r\n", "\r"], "\n", $new);

        return $this->renderInline($old, $new);
    }

    /**
     * Compare two strings and return a side-by-side HTML diff.
     *
     * @param string $old Original text
     * @param string $new Changed text
     *
     * @return string HTML diff output
     */
    public function compareSideBySide(string $old, string $new): string
    {
        // Normalize line endings
        $old = str_replace(["\r\n", "\r"], "\n", $old);
        $new = str_replace(["\r\n", "\r"], "\n", $new);

        return $this->renderSideBySide($old, $new);
    }

    /**
     * Render side-by-side diff as HTML table.
     *
     * @param string $old
     * @param string $new
     *
     * @return string
     */
    protected function renderSideBySide(string $old, string $new): string
    {
        $diff = $this->getDiffArray($old, $new);

        // Find which lines to show (changes + context)
        $showLines = $this->getLinesToShow($diff);

        // Group consecutive changes
        $rows = [];
        $oldBuffer = [];
        $newBuffer = [];
        $lastShownIndex = -1;

        foreach ($diff as $index => [$line, $type]) {
            if (!isset($showLines[$index])) {
                $this->flushSideBySideBuffers($rows, $oldBuffer, $newBuffer);

                continue;
            }

            if ($lastShownIndex >= 0 && $index > $lastShownIndex + 1) {
                $this->flushSideBySideBuffers($rows, $oldBuffer, $newBuffer);
                $rows[] = ['type' => 'separator'];
            }
            $lastShownIndex = $index;

            $line = rtrim($line, "\r\n");

            switch ($type) {
                case Differ::OLD:
                    $this->flushSideBySideBuffers($rows, $oldBuffer, $newBuffer);
                    $rows[] = ['type' => 'unchanged', 'old' => $line, 'new' => $line];

                    break;
                case Differ::REMOVED:
                    $oldBuffer[] = $line;

                    break;
                case Differ::ADDED:
                    $newBuffer[] = $line;

                    break;
            }
        }

        $this->flushSideBySideBuffers($rows, $oldBuffer, $newBuffer);

        $html = '<table class="diff-wrapper diff-side-by-side">';
        $html .= '<thead><tr><th class="line-num">#</th><th>Before</th><th class="line-num">#</th><th>After</th></tr></thead>';
        $html .= '<tbody>';

        $oldNum = 0;
        $newNum = 0;

        foreach ($rows as $row) {
            if ($row['type'] === 'separator') {
                $html .= '<tr class="separator"><td colspan="4" class="text-center text-muted">...</td></tr>';
            } elseif ($row['type'] === 'unchanged') {
                $oldNum++;
                $newNum++;
                $html .= '<tr class="unchanged">';
                $html .= '<td class="line-num">' . $oldNum . '</td>';
                $html .= '<td>' . htmlspecialchars($row['old']) . '</td>';
                $html .= '<td class="line-num">' . $newNum . '</td>';
                $html .= '<td>' . htmlspecialchars($row['new']) . '</td>';
                $html .= '</tr>';
            } else {
                $oldNum++;
                $newNum++;
                $html .= '<tr class="changed">';
                $html .= '<td class="line-num old">' . ($row['old'] !== null ? $oldNum : '') . '</td>';

                if (isset($row['oldHtml'])) {
                    $html .= '<td class="old">' . $row['oldHtml'] . '</td>';
                } else {
                    $html .= '<td class="old">' . ($row['old'] !== null ? '<del>' . htmlspecialchars($row['old']) . '</del>' : '') . '</td>';
                }

                $html .= '<td class="line-num new">' . ($row['new'] !== null ? $newNum : '') . '</td>';

                if (isset($row['newHtml'])) {
                    $html .= '<td class="new">' . $row['newHtml'] . '</td>';
                } else {
                    $html .= '<td class="new">' . ($row['new'] !== null ? '<ins>' . htmlspecialchars($row['new']) . '</ins>' : '') . '</td>';
                }

                $html .= '</tr>';

                if ($row['old'] === null) {
                    $oldNum--;
                }
                if ($row['new'] === null) {
                    $newNum--;
                }
            }
        }

        $html .= '</tbody></table>';

        return $html;
    }

    /**
     * Flush side-by-side buffers into rows.
     *
     * @param array<array<string, mixed>> $rows
     * @param array<string> $oldBuffer
     * @param array<string> $newBuffer
     *
     * @return void
     */
    protected function flushSideBySideBuffers(array &$rows, array &$oldBuffer, array &$newBuffer): void
    {
        $maxLen = max(count($oldBuffer), count($newBuffer));
        for ($i = 0; $i < $maxLen; $i++) {
            $oldLine = $oldBuffer[$i] ?? null;
            $newLine = $newBuffer[$i] ?? null;

            if ($oldLine !== null && $newLine !== null) {
                [$oldHtml, $newHtml] = $this->computeCharDiff($oldLine, $newLine);
                $rows[] = [
                    'type' => 'changed',
                    'old' => $oldLine,
                    'new' => $newLine,
                    'oldHtml' => $oldHtml,
                    'newHtml' => $newHtml,
                ];
            } else {
                $rows[] = [
                    'type' => 'changed',
                    'old' => $oldLine,
                    'new' => $newLine,
                ];
            }
        }
        $oldBuffer = [];
        $newBuffer = [];
    }

    /**
     * Render inline diff as HTML table.
     *
     * @param string $old
     * @param string $new
     *
     * @return string
     */
    protected function renderInline(string $old, string $new): string
    {
        $diff = $this->getDiffArray($old, $new);

        // Find which lines to show (changes + context)
        $showLines = $this->getLinesToShow($diff);

        // Group consecutive removed/added lines for character-level diff
        $processedDiff = $this->groupChangesForCharDiff($diff);

        $html = '<table class="diff-wrapper diff-inline">';
        $html .= '<thead><tr><th class="line-num">#</th><th class="sign"></th><th>Content</th></tr></thead>';
        $html .= '<tbody>';

        $lineNum = 0;
        $lastShownIndex = -1;

        foreach ($processedDiff as $item) {
            if (!isset($showLines[$item['origIndex']])) {
                continue;
            }

            // Add separator if there's a gap
            if ($lastShownIndex >= 0 && $item['origIndex'] > $lastShownIndex + 1) {
                $html .= '<tr class="separator"><td colspan="3" class="text-center text-muted">...</td></tr>';
            }
            $lastShownIndex = $item['origIndex'];

            $line = rtrim($item['line'], "\r\n");
            $lineNum++;

            switch ($item['type']) {
                case Differ::OLD:
                    $html .= '<tr class="unchanged">';
                    $html .= '<td class="line-num">' . $lineNum . '</td>';
                    $html .= '<td class="sign"> </td>';
                    $html .= '<td>' . htmlspecialchars($line) . '</td>';
                    $html .= '</tr>';

                    break;
                case Differ::ADDED:
                    $html .= '<tr class="added">';
                    $html .= '<td class="line-num">+</td>';
                    $html .= '<td class="sign">+</td>';
                    if (isset($item['html'])) {
                        $html .= '<td>' . $item['html'] . '</td>';
                    } else {
                        $html .= '<td><ins>' . htmlspecialchars($line) . '</ins></td>';
                    }
                    $html .= '</tr>';

                    break;
                case Differ::REMOVED:
                    $html .= '<tr class="removed">';
                    $html .= '<td class="line-num">-</td>';
                    $html .= '<td class="sign">-</td>';
                    if (isset($item['html'])) {
                        $html .= '<td>' . $item['html'] . '</td>';
                    } else {
                        $html .= '<td><del>' . htmlspecialchars($line) . '</del></td>';
                    }
                    $html .= '</tr>';
                    $lineNum--;

                    break;
            }
        }

        $html .= '</tbody></table>';

        return $html;
    }

    /**
     * Group consecutive removed/added lines and compute character-level diffs.
     *
     * @param array<array{0: string, 1: int}> $diff
     *
     * @return array<array<string, mixed>>
     */
    protected function groupChangesForCharDiff(array $diff): array
    {
        $result = [];
        $removedBuffer = [];
        $removedIndices = [];

        foreach ($diff as $index => [$line, $type]) {
            if ($type === Differ::REMOVED) {
                $removedBuffer[] = $line;
                $removedIndices[] = $index;
            } elseif ($type === Differ::ADDED && $removedBuffer) {
                // Match removed with added for character diff
                $removedLine = array_shift($removedBuffer);
                $removedIndex = array_shift($removedIndices);

                [$oldHtml, $newHtml] = $this->computeCharDiff(rtrim($removedLine, "\r\n"), rtrim($line, "\r\n"));

                $result[] = [
                    'line' => $removedLine,
                    'type' => Differ::REMOVED,
                    'origIndex' => $removedIndex,
                    'html' => $oldHtml,
                ];
                $result[] = [
                    'line' => $line,
                    'type' => Differ::ADDED,
                    'origIndex' => $index,
                    'html' => $newHtml,
                ];
            } else {
                // Flush any remaining removed lines
                foreach ($removedBuffer as $i => $removed) {
                    $result[] = [
                        'line' => $removed,
                        'type' => Differ::REMOVED,
                        'origIndex' => $removedIndices[$i],
                    ];
                }
                $removedBuffer = [];
                $removedIndices = [];

                $result[] = [
                    'line' => $line,
                    'type' => $type,
                    'origIndex' => $index,
                ];
            }
        }

        // Flush any remaining removed lines
        foreach ($removedBuffer as $i => $removed) {
            $result[] = [
                'line' => $removed,
                'type' => Differ::REMOVED,
                'origIndex' => $removedIndices[$i],
            ];
        }

        return $result;
    }

    /**
     * Get indices of lines to show (changes + context).
     *
     * @param array<array{0: string, 1: int}> $diff
     *
     * @return array<int, bool>
     */
    protected function getLinesToShow(array $diff): array
    {
        $showLines = [];
        $changeIndices = [];

        // Find all change indices
        foreach ($diff as $index => [$line, $type]) {
            if ($type === Differ::ADDED || $type === Differ::REMOVED) {
                $changeIndices[] = $index;
            }
        }

        // Mark lines to show (changes + context)
        foreach ($changeIndices as $changeIndex) {
            for ($i = $changeIndex - $this->contextLines; $i <= $changeIndex + $this->contextLines; $i++) {
                if ($i >= 0 && $i < count($diff)) {
                    $showLines[$i] = true;
                }
            }
        }

        return $showLines;
    }

    /**
     * Compute character-level diff between two lines.
     *
     * @param string $oldLine
     * @param string $newLine
     *
     * @return array{0: string, 1: string} [oldHtml, newHtml]
     */
    protected function computeCharDiff(string $oldLine, string $newLine): array
    {
        $oldChars = preg_split('//u', $oldLine, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $newChars = preg_split('//u', $newLine, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        // Use longest common subsequence to find matching characters
        $lcs = $this->longestCommonSubsequence($oldChars, $newChars);

        // Build HTML for old line (highlighting removed chars)
        $oldHtml = $this->buildCharDiffHtml($oldChars, $lcs, 'del');

        // Build HTML for new line (highlighting added chars)
        $newHtml = $this->buildCharDiffHtml($newChars, $lcs, 'ins');

        return [$oldHtml, $newHtml];
    }

    /**
     * Build HTML with character-level highlighting.
     *
     * @param array<string> $chars
     * @param array<string> $lcs
     * @param string $tag 'del' or 'ins'
     *
     * @return string
     */
    protected function buildCharDiffHtml(array $chars, array $lcs, string $tag): string
    {
        $html = '';
        $lcsIndex = 0;
        $inTag = false;

        foreach ($chars as $char) {
            $isInLcs = $lcsIndex < count($lcs) && $lcs[$lcsIndex] === $char;

            if ($isInLcs) {
                if ($inTag) {
                    $html .= '</' . $tag . '>';
                    $inTag = false;
                }
                $html .= htmlspecialchars($char);
                $lcsIndex++;
            } else {
                if (!$inTag) {
                    $html .= '<' . $tag . '>';
                    $inTag = true;
                }
                $html .= htmlspecialchars($char);
            }
        }

        if ($inTag) {
            $html .= '</' . $tag . '>';
        }

        return $html;
    }

    /**
     * Compute longest common subsequence of two arrays.
     *
     * @param array<string> $a
     * @param array<string> $b
     *
     * @return array<string>
     */
    protected function longestCommonSubsequence(array $a, array $b): array
    {
        $m = count($a);
        $n = count($b);

        // Build LCS length table
        $dp = array_fill(0, $m + 1, array_fill(0, $n + 1, 0));

        for ($i = 1; $i <= $m; $i++) {
            for ($j = 1; $j <= $n; $j++) {
                if ($a[$i - 1] === $b[$j - 1]) {
                    $dp[$i][$j] = $dp[$i - 1][$j - 1] + 1;
                } else {
                    $dp[$i][$j] = max($dp[$i - 1][$j], $dp[$i][$j - 1]);
                }
            }
        }

        // Backtrack to find LCS
        $lcs = [];
        $i = $m;
        $j = $n;
        while ($i > 0 && $j > 0) {
            if ($a[$i - 1] === $b[$j - 1]) {
                array_unshift($lcs, $a[$i - 1]);
                $i--;
                $j--;
            } elseif ($dp[$i - 1][$j] > $dp[$i][$j - 1]) {
                $i--;
            } else {
                $j--;
            }
        }

        return $lcs;
    }

    /**
     * Get diff as array using sebastian/diff.
     *
     * @param string $old
     * @param string $new
     *
     * @return array<array{0: string, 1: int}>
     */
    protected function getDiffArray(string $old, string $new): array
    {
        $builder = new UnifiedDiffOutputBuilder();
        $differ = new Differ($builder);

        return $differ->diffToArray($old, $new);
    }
}
