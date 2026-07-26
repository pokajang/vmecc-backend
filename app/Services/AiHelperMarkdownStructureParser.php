<?php

namespace App\Services;

use Illuminate\Support\Str;

class AiHelperMarkdownStructureParser
{
    /**
     * @return array<int, array{content: string, heading_path: array<int, string>, content_type: string, page_start: ?int, page_end: ?int, search_text: string}>
     */
    public function chunks(string $markdown, int $targetSize): array
    {
        $lines = preg_split('/\R/u', $markdown) ?: [];
        $headingPath = [];
        $blocks = [];
        $buffer = [];
        $bufferType = 'text';
        $sourcePage = null;

        $flush = function () use (&$blocks, &$buffer, &$bufferType, &$headingPath, &$sourcePage): void {
            $content = trim(implode("\n", $buffer));
            if ($content !== '') {
                $blocks[] = $this->block($content, $headingPath, $bufferType, $sourcePage);
            }
            $buffer = [];
            $bufferType = 'text';
        };

        foreach ($lines as $line) {
            if (preg_match('/^\s*<!--\s*source-page:\s*0*(\d+)\s*-->\s*$/i', $line, $pageMarker)) {
                $flush();
                $sourcePage = max(1, (int) $pageMarker[1]);

                continue;
            }

            if (preg_match('/^(#{1,6})\s+(.+)$/u', trim($line), $heading)) {
                $flush();
                $level = strlen($heading[1]);
                $headingPath = array_slice($headingPath, 0, $level - 1);
                $headingPath[$level - 1] = trim($heading[2]);

                continue;
            }

            if (preg_match('/^\s*!\[([^]]*)]\(([^)]+)\)\s*$/u', $line)) {
                $flush();
                $blocks[] = $this->block(trim($line), $headingPath, 'visual_reference', $sourcePage);

                continue;
            }

            $isTable = str_starts_with(ltrim($line), '|');
            if ($buffer !== [] && (($bufferType === 'table') !== $isTable)) {
                $flush();
            }
            if ($isTable) {
                $bufferType = 'table';
            }

            if (trim($line) === '') {
                $flush();

                continue;
            }

            $buffer[] = rtrim($line);
        }
        $flush();

        return $this->packBlocks($blocks, max(600, $targetSize));
    }

    /** @return array{content: string, heading_path: array<int, string>, content_type: string, page_start: ?int, page_end: ?int, search_text: string} */
    private function block(
        string $content,
        array $headingPath,
        string $type,
        ?int $sourcePage = null,
    ): array
    {
        $page = $sourcePage;
        if ($type === 'visual_reference' && preg_match('/\b(?:pdf\s+)?page\s*0*(\d+)\b/i', $content, $match)) {
            $page = (int) $match[1];
        }

        return [
            'content' => $content,
            'heading_path' => array_values($headingPath),
            'content_type' => $type,
            'page_start' => $page,
            'page_end' => $page,
            'search_text' => trim(implode(' > ', $headingPath).' '.$content),
        ];
    }

    private function packBlocks(array $blocks, int $targetSize): array
    {
        $chunks = [];
        $current = null;

        foreach ($blocks as $block) {
            if (Str::length($block['content']) > $targetSize) {
                if ($current !== null) {
                    $chunks[] = $current;
                    $current = null;
                }
                $parts = $block['content_type'] === 'table'
                    ? $this->splitTableBlock($block, $targetSize)
                    : $this->splitLongBlock($block, $targetSize);
                foreach ($parts as $part) {
                    $chunks[] = $part;
                }

                continue;
            }

            $canJoin = $current !== null
                && $current['content_type'] === $block['content_type']
                && $current['heading_path'] === $block['heading_path']
                && Str::length($current['content']."\n\n".$block['content']) <= $targetSize;
            if ($canJoin) {
                $current['content'] .= "\n\n".$block['content'];
                $current['search_text'] .= "\n\n".$block['content'];

                continue;
            }
            if ($current !== null) {
                $chunks[] = $current;
            }
            $current = $block;
        }

        if ($current !== null) {
            $chunks[] = $current;
        }

        return $chunks;
    }

    private function splitLongBlock(array $block, int $targetSize): array
    {
        $parts = [];
        $remaining = $block['content'];
        while (Str::length($remaining) > $targetSize) {
            $slice = Str::substr($remaining, 0, $targetSize);
            $breakAt = max((int) strrpos($slice, "\n"), (int) strrpos($slice, '. '));
            if ($breakAt < (int) ($targetSize * 0.55)) {
                $breakAt = $targetSize;
            }
            $content = trim(Str::substr($remaining, 0, $breakAt + 1));
            $parts[] = $this->block(
                $content,
                $block['heading_path'],
                $block['content_type'],
                $block['page_start'],
            );
            $remaining = trim(Str::substr($remaining, $breakAt + 1));
        }
        if ($remaining !== '') {
            $parts[] = $this->block(
                $remaining,
                $block['heading_path'],
                $block['content_type'],
                $block['page_start'],
            );
        }

        return $parts;
    }

    private function splitTableBlock(array $block, int $targetSize): array
    {
        $rows = preg_split('/\R/u', $block['content']) ?: [];
        $hasHeader = isset($rows[1]) && preg_match('/^\s*\|?(?:\s*:?-{3,}:?\s*\|)+\s*$/u', $rows[1]) === 1;
        $header = $hasHeader ? array_slice($rows, 0, 2) : [];
        $body = array_slice($rows, count($header));
        $parts = [];
        $current = $header;
        foreach ($body as $row) {
            if (Str::length($row) > $targetSize) {
                if (count($current) > count($header)) {
                    $parts[] = $this->block(
                        implode("\n", $current),
                        $block['heading_path'],
                        'table',
                        $block['page_start'],
                    );
                    $current = $header;
                }
                foreach ($this->splitLongBlock($this->block(
                    $row,
                    $block['heading_path'],
                    'table',
                    $block['page_start'],
                ), $targetSize) as $part) {
                    $parts[] = $part;
                }

                continue;
            }
            $candidate = implode("\n", array_merge($current, [$row]));
            if (count($current) > count($header) && Str::length($candidate) > $targetSize) {
                $parts[] = $this->block(
                    implode("\n", $current),
                    $block['heading_path'],
                    'table',
                    $block['page_start'],
                );
                $current = $header;
            }
            $current[] = $row;
        }
        if (count($current) > count($header) || $parts === []) {
            $parts[] = $this->block(
                implode("\n", $current),
                $block['heading_path'],
                'table',
                $block['page_start'],
            );
        }

        return $parts;
    }
}
