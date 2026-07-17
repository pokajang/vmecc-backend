<?php

namespace App\Services;

use Illuminate\Support\Str;
use RuntimeException;

class AiHelperMarkdownKnowledgeParser
{
    private const ALLOWED_FRONTMATTER_KEYS = [
        'key',
        'title',
        'scope_type',
        'module_key',
        'route_key',
        'tags',
        'version',
        'summary',
        'active',
        'knowledge_type',
        'module_gate',
        'required_permissions',
        'permission_match',
        'allowed_roles',
        'owner',
        'reviewed_on',
        'review_due_on',
        'release_status',
    ];

    /**
     * @return array{frontmatter: array<string, mixed>, content: string}
     */
    public function parseFile(string $file, bool $requireFrontmatter = false): array
    {
        $raw = file_get_contents($file);
        if ($raw === false) {
            throw new RuntimeException("Could not read Ask AI Markdown file: {$file}.");
        }

        return $this->parseString($raw, $file, $requireFrontmatter);
    }

    /**
     * @return array{frontmatter: array<string, mixed>, content: string}
     */
    public function parseString(string $raw, string $source = 'Markdown upload', bool $requireFrontmatter = false): array
    {
        $frontmatter = [];
        $content = trim($raw);

        if (preg_match('/\A---\R(.*?)\R---\R?(.*)\z/s', $raw, $matches)) {
            $frontmatter = $this->parseFrontmatter($matches[1], $source);
            $content = trim($matches[2]);
        } elseif ($requireFrontmatter) {
            throw new RuntimeException("Ask AI knowledge file requires frontmatter: {$source}.");
        }

        if ($content === '') {
            throw new RuntimeException("Ask AI knowledge file has no content: {$source}.");
        }

        return [
            'frontmatter' => $frontmatter,
            'content' => $content,
        ];
    }

    /**
     * @param  array<string, mixed>  $frontmatter
     */
    public function requiredString(array $frontmatter, string $key, string $source): string
    {
        $value = trim((string) ($frontmatter[$key] ?? ''));
        if ($value === '') {
            throw new RuntimeException("Ask AI knowledge file missing {$key}: {$source}.");
        }

        return $value;
    }

    public function booleanValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(Str::lower(trim((string) $value)), ['1', 'true', 'yes', 'active'], true);
    }

    /**
     * @param  array<int, string>  $extraTags
     * @return array<int, string>
     */
    public function tags(mixed $rawTags, array $extraTags = []): array
    {
        $tags = is_array($rawTags)
            ? $rawTags
            : (is_string($rawTags) ? preg_split('/\s*,\s*/', $rawTags) : []);

        return collect($tags ?: [])
            ->merge($extraTags)
            ->filter(fn ($tag) => trim((string) $tag) !== '')
            ->map(fn ($tag) => Str::limit(Str::lower(trim((string) $tag)), 80, ''))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function parseFrontmatter(string $frontmatter, string $source): array
    {
        $values = [];
        $activeListKey = null;
        foreach (preg_split('/\R/', $frontmatter) ?: [] as $rawLine) {
            $line = trim($rawLine);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (str_starts_with($line, '- ')) {
                if ($activeListKey === null) {
                    throw new RuntimeException("Invalid Ask AI knowledge frontmatter list in {$source}: {$line}");
                }
                $values[$activeListKey][] = $this->scalarValue(trim(substr($line, 2)));

                continue;
            }

            if (! str_contains($line, ':')) {
                throw new RuntimeException("Invalid Ask AI knowledge frontmatter line in {$source}: {$line}");
            }

            [$key, $value] = array_map('trim', explode(':', $line, 2));
            if (! in_array($key, self::ALLOWED_FRONTMATTER_KEYS, true)) {
                throw new RuntimeException("Unsupported Ask AI knowledge frontmatter key in {$source}: {$key}");
            }
            if ($value === '[]') {
                $values[$key] = [];
                $activeListKey = $key;
            } elseif ($value === '') {
                $values[$key] = [];
                $activeListKey = $key;
            } else {
                $values[$key] = $this->scalarValue($value);
                $activeListKey = null;
            }
        }

        return $values;
    }

    private function scalarValue(string $value): mixed
    {
        $trimmed = trim($value, " \t\n\r\0\x0B\"'");
        if (in_array(Str::lower($trimmed), ['true', 'false'], true)) {
            return Str::lower($trimmed) === 'true';
        }

        return $trimmed;
    }
}
