<?php

namespace App\Console\Commands;

use App\Services\AiHelperMarkdownKnowledgeParser;
use App\Services\AiHelperSystemGuideCatalog;
use Illuminate\Console\Command;
use Throwable;

class AuditAiHelperSystemGuides extends Command
{
    protected $signature = 'ai-helper:system-guides:audit
        {--json : Emit machine-readable JSON}';

    protected $description = 'Validate the complete final, user-facing VMECC system-guide corpus.';

    public function handle(
        AiHelperMarkdownKnowledgeParser $parser,
        AiHelperSystemGuideCatalog $catalog,
    ): int {
        $files = glob(database_path('ai-helper-system-guides/*.md')) ?: [];
        sort($files);

        $guides = [];
        $errors = $catalog->validateRegistry();
        foreach ($files as $file) {
            $key = basename($file, '.md');
            try {
                $parsed = $parser->parseFile($file, true);
                $key = trim((string) ($parsed['frontmatter']['key'] ?? $key));
                $metadata = $catalog->validate($parsed['frontmatter'], $parsed['content'], $file);
                if ($metadata['version'] === AiHelperSystemGuideCatalog::FINAL_VERSION) {
                    $catalog->validateFinalContent($parsed['content'], $file);
                }
                $guides[$key] = [
                    'key' => $key,
                    'version' => $metadata['version'],
                    'release_status' => $metadata['release_status'],
                    'active' => $metadata['active'],
                    'owner' => $metadata['owner'],
                    'content_sha256' => hash('sha256', $parsed['content']),
                    'verification_dossier' => is_file(base_path("docs/ai-helper-system-guide-reviews/{$key}.md")),
                    'valid' => true,
                ];
            } catch (Throwable $exception) {
                $errors[] = $key.': '.$exception->getMessage();
                $guides[$key] = [
                    'key' => $key,
                    'valid' => false,
                    'error' => $exception->getMessage(),
                ];
            }
        }

        $expectedKeys = $catalog->keys();
        $actualKeys = array_keys($guides);
        $missing = array_values(array_diff($expectedKeys, $actualKeys));
        $unknown = array_values(array_diff($actualKeys, $expectedKeys));
        foreach ($missing as $key) {
            $errors[] = 'Missing system guide: '.$key;
        }
        foreach ($unknown as $key) {
            $errors[] = 'Unknown system guide: '.$key;
        }

        $validGuides = collect($guides)->where('valid', true);
        $final = $validGuides->where('release_status', AiHelperSystemGuideCatalog::RELEASE_FINAL)->count();
        $active = $validGuides->where('active', true)->count();
        $versionThree = $validGuides->where('version', AiHelperSystemGuideCatalog::FINAL_VERSION)->count();
        $dossiers = $validGuides->where('verification_dossier', true)->count();
        $errors = array_values(array_unique($errors));
        $ready = count($files) === $catalog->expectedCount()
            && count($guides) === $catalog->expectedCount()
            && $validGuides->count() === $catalog->expectedCount()
            && $final === $catalog->expectedCount()
            && $active === $catalog->expectedCount()
            && $versionThree === $catalog->expectedCount()
            && $dossiers === $catalog->expectedCount()
            && $errors === [];

        $payload = [
            'ready' => $ready,
            'expected' => $catalog->expectedCount(),
            'files' => count($files),
            'valid' => $validGuides->count(),
            'version_3' => $versionThree,
            'final' => $final,
            'active' => $active,
            'verification_dossiers' => $dossiers,
            'missing' => $missing,
            'unknown' => $unknown,
            'errors' => $errors,
            'guides' => array_values($guides),
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->components->twoColumnDetail('Final corpus ready', $ready ? '<fg=green>yes</>' : '<fg=red>no</>');
            $this->components->twoColumnDetail('Guides', count($files).' / '.$catalog->expectedCount());
            $this->components->twoColumnDetail('Version 3', (string) $versionThree);
            $this->components->twoColumnDetail('Final and active', $final.' / '.$active);
            $this->components->twoColumnDetail('Verification dossiers', $dossiers.' / '.$catalog->expectedCount());
            foreach ($errors as $error) {
                $this->components->error($error);
            }
        }

        return $ready ? self::SUCCESS : self::FAILURE;
    }
}
