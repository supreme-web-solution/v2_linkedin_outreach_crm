<?php

namespace App\V2\Services;

class EmailBodyFormatter
{
    /**
     * @return array{main: string, quoted: string|null, preview: string, quote_header: string|null}
     */
    public function format(string $raw): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", trim($raw));
        if ($text === '') {
            return ['main' => '', 'quoted' => null, 'preview' => '', 'quote_header' => null];
        }

        $splitAt = $this->findQuoteSplitIndex($text);
        $main = trim(substr($text, 0, $splitAt));
        $quotedRaw = trim(substr($text, $splitAt));

        if ($main === '' && $quotedRaw !== '') {
            $main = $this->stripQuoteMarkers($quotedRaw);
            $quotedRaw = '';
        }

        $quoteHeader = $this->resolveQuoteHeader($main, $quotedRaw);
        $main = $this->stripOnWroteHeaderFromEnd($main);
        $quoted = $quotedRaw !== '' ? $this->stripQuoteMarkers($quotedRaw) : null;
        $previewSource = $main !== '' ? $main : ($quoted ?? $text);
        $preview = preg_replace('/\s+/u', ' ', trim($previewSource)) ?? trim($previewSource);

        return [
            'main' => $main,
            'quoted' => $quoted !== '' ? $quoted : null,
            'preview' => $preview,
            'quote_header' => $quoteHeader,
        ];
    }

    public function preview(string $text): string
    {
        return $this->format($text)['preview'];
    }

    private function resolveQuoteHeader(string $main, string $quotedRaw): ?string
    {
        $block = $this->extractOnWroteHeaderBlock($quotedRaw)
            ?? $this->extractOnWroteHeaderBlock($main);

        if ($block === null) {
            return null;
        }

        $label = $this->formatQuoteHeaderLabel($block);

        return $label !== '' ? $label : null;
    }

    private function extractOnWroteHeaderBlock(string $text): ?string
    {
        if (preg_match('/((?:^|[\n\s])On .+?(?:\n(?!>)[^\n]+)*\n?wrote:\s*)/is', $text, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    private function formatQuoteHeaderLabel(string $block): string
    {
        $parts = [];

        foreach (explode("\n", $block) as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || preg_match('/^wrote:\s*$/i', $trimmed)) {
                continue;
            }

            if (preg_match('/^On\s+(.+)$/i', $trimmed, $matches)) {
                $part = preg_replace('/\s+wrote:\s*$/i', '', trim($matches[1]));
                if ($part !== '') {
                    $parts[] = $part;
                }

                continue;
            }

            $parts[] = preg_replace('/\s+wrote:\s*$/i', '', $trimmed) ?? $trimmed;
        }

        $label = implode(' · ', array_values(array_filter($parts)));
        $label = trim(preg_replace('/\s+wrote:\s*$/i', '', $label) ?? $label);

        return $this->prettifyQuoteHeaderLabel($label);
    }

    private function prettifyQuoteHeaderLabel(string $label): string
    {
        if (str_contains($label, ' · ')) {
            return $label;
        }

        if (preg_match('/^(.+?\s)(\S+@\S+)$/u', $label, $matches)) {
            return trim($matches[1]).' · '.trim($matches[2]);
        }

        return $label;
    }

    private function findQuoteSplitIndex(string $text): int
    {
        $splitAt = strlen($text);

        $patterns = [
            '/(?:^|[\n\s])On .+?(?:\n(?!>)[^\n]+)*\n?wrote:\s*/is',
            '/\n-{2,}\s*Original Message\s*-{2,}/i',
            '/\nFrom: .+\n(?:Sent|Date): .+\n/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $matches, PREG_OFFSET_CAPTURE)) {
                $splitAt = min($splitAt, (int) $matches[0][1]);
            }
        }

        $lines = explode("\n", $text);
        $quoteLineIndex = null;
        $contentBeforeQuote = 0;

        foreach ($lines as $index => $line) {
            $trimmed = ltrim($line);
            if (str_starts_with($trimmed, '>')) {
                if ($quoteLineIndex === null) {
                    $quoteLineIndex = $index;
                }
            } elseif (trim($line) !== '' && $quoteLineIndex === null) {
                $contentBeforeQuote++;
            }
        }

        if ($quoteLineIndex !== null && $contentBeforeQuote > 0) {
            $splitLineIndex = $this->quoteBlockStartLineIndex($lines, $quoteLineIndex);

            $lineOffset = 0;
            for ($i = 0; $i < $splitLineIndex; $i++) {
                $lineOffset += strlen($lines[$i]) + 1;
            }

            $splitAt = min($splitAt, $lineOffset);
        }

        return max(0, $splitAt);
    }

    /**
     * @param  array<int, string>  $lines
     */
    private function quoteBlockStartLineIndex(array $lines, int $quoteLineIndex): int
    {
        $start = $quoteLineIndex;

        for ($i = $quoteLineIndex - 1; $i >= 0; $i--) {
            $lineTrimmed = trim($lines[$i]);
            if ($lineTrimmed === '') {
                continue;
            }

            if ($this->isOnWroteHeaderLine($lineTrimmed) || preg_match('/^wrote:\s*$/i', $lineTrimmed)) {
                $start = $i;

                continue;
            }

            if (preg_match('/^On .+/i', $lineTrimmed) && $this->lineLooksLikeOnWroteContinuation($lines, $i)) {
                $start = $i;

                continue;
            }

            if (str_contains($lineTrimmed, '@') && $this->lineLooksLikeOnWroteContinuation($lines, $i)) {
                $start = $i;

                continue;
            }

            break;
        }

        return $start;
    }

    /**
     * @param  array<int, string>  $lines
     */
    private function lineLooksLikeOnWroteContinuation(array $lines, int $index): bool
    {
        for ($j = $index + 1; $j < min($index + 4, count($lines)); $j++) {
            $nextTrimmed = trim($lines[$j]);
            if ($nextTrimmed === '') {
                continue;
            }

            if (preg_match('/^wrote:\s*$/i', $nextTrimmed)) {
                return true;
            }

            return (bool) preg_match('/^.+ wrote:\s*$/i', $nextTrimmed);
        }

        return false;
    }

    private function isOnWroteHeaderLine(string $line): bool
    {
        return (bool) preg_match('/^On .+ wrote:\s*$/i', $line);
    }

    private function stripOnWroteHeaderFromEnd(string $text): string
    {
        $stripped = preg_replace('/(?:\n\s*)+On .+?(?:\n(?!>)[^\n]+)*\n?wrote:\s*$/is', '', $text);

        return trim($stripped ?? $text);
    }

    private function stripQuoteMarkers(string $text): string
    {
        $text = preg_replace('/^(?:On .+?(?:\n(?!>)[^\n]+)*\n?wrote:\s*\n?)/is', '', $text) ?? $text;

        $lines = explode("\n", $text);
        $cleaned = [];

        foreach ($lines as $line) {
            $lineTrimmed = trim($line);
            if ($lineTrimmed === '') {
                continue;
            }

            if ($this->isOnWroteHeaderLine($lineTrimmed) || preg_match('/^wrote:\s*$/i', $lineTrimmed)) {
                continue;
            }

            if (preg_match('/^.+ wrote:\s*$/i', $lineTrimmed) && str_contains($lineTrimmed, '@')) {
                continue;
            }

            if (preg_match('/^On .+/i', $lineTrimmed)) {
                continue;
            }

            $trimmed = ltrim($line);
            if (str_starts_with($trimmed, '>')) {
                $trimmed = ltrim(substr($trimmed, 1));
            }

            $trimmed = trim($trimmed);
            if ($trimmed !== '') {
                $cleaned[] = $trimmed;
            }
        }

        $result = trim(implode("\n", $cleaned));

        return preg_replace("/\n{3,}/", "\n\n", $result) ?? $result;
    }
}
