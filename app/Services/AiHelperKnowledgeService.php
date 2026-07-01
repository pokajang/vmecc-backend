<?php

namespace App\Services;

use App\Models\AiHelperKnowledgeEntry;
use App\Models\AiHelperKnowledgeChunk;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class AiHelperKnowledgeService
{
    public function buildContext(array $rawContext, ?User $user = null, string $message = ''): array
    {
        $context = $this->normalizePageContext($rawContext);
        $guidance = $this->guidanceForContext($context, $user, $message);

        return [
            'page' => $context,
            'guidance' => $guidance,
            'available' => count($guidance) > 0,
        ];
    }

    public function normalizePageContext(array $rawContext): array
    {
        $path = $this->cleanPath((string) ($rawContext['path'] ?? $rawContext['route_path'] ?? ''));
        $routeName = trim((string) ($rawContext['route_name'] ?? $rawContext['name'] ?? ''));
        $title = trim((string) ($rawContext['title'] ?? ''));
        $search = trim((string) ($rawContext['search'] ?? ''));
        $params = Arr::get($rawContext, 'params', []);

        if (is_string($params)) {
            $decoded = json_decode($params, true);
            $params = is_array($decoded) ? $decoded : [];
        }

        $routeKey = $this->routeKeyForPath($path);

        return [
            'path' => $path ?: '/',
            'route_key' => $routeKey,
            'route_name' => $routeName ?: $this->titleForRouteKey($routeKey),
            'module_key' => $this->moduleKeyForRouteKey($routeKey),
            'title' => $title ?: $routeName ?: $this->titleForRouteKey($routeKey),
            'search' => $search,
            'params' => is_array($params) ? $this->sanitizeParams($params) : [],
        ];
    }

    public function guidanceForContext(array $context, ?User $user = null, string $message = ''): array
    {
        $moduleKey = (string) ($context['module_key'] ?? '');
        $routeKey = (string) ($context['route_key'] ?? '');
        $limit = max(1, (int) config('ai_helper.knowledge_retrieval_limit', 6));

        $chunks = AiHelperKnowledgeChunk::query()
            ->with('knowledgeEntry:id,title,module_key,route_key,tags,version,uploaded_by,visibility,review_status,status,active,deleted_at')
            ->where('active', true)
            ->where(function ($query) use ($moduleKey, $routeKey) {
                $this->applyScopeFilter($query, $moduleKey, $routeKey);
            })
            ->whereHas('knowledgeEntry', function ($query) use ($user) {
                $this->applyUsableEntryFilter($query, $user);
            })
            ->latest('updated_at')
            ->limit(80)
            ->get();

        $ranked = $chunks
            ->map(fn (AiHelperKnowledgeChunk $chunk) => $this->formatChunkGuidance($chunk, $moduleKey, $routeKey, $message))
            ->sortByDesc('score')
            ->take($limit)
            ->values();

        if ($ranked->count() < $limit) {
            $fallbackEntries = AiHelperKnowledgeEntry::query()
                ->whereDoesntHave('chunks')
                ->where(function ($query) use ($moduleKey, $routeKey) {
                    $this->applyScopeFilter($query, $moduleKey, $routeKey);
                })
                ->where(function ($query) use ($user) {
                    $this->applyUsableEntryFilter($query, $user);
                })
                ->latest('updated_at')
                ->limit($limit - $ranked->count())
                ->get()
                ->map(fn (AiHelperKnowledgeEntry $entry) => $this->formatEntryGuidance($entry, $moduleKey, $routeKey, $message));

            $ranked = $ranked->concat($fallbackEntries)
                ->sortByDesc('score')
                ->take($limit)
                ->values();
        }

        return $ranked->map(fn (array $entry) => Arr::except($entry, ['score']))->all();
    }

    public function instructionsFor(array $contextEnvelope, string $responseLanguage = 'auto'): string
    {
        $page = $contextEnvelope['page'] ?? [];
        $guidance = $contextEnvelope['guidance'] ?? [];
        $languageInstruction = $this->languageInstruction($responseLanguage);
        $guidanceText = collect($guidance)->map(function ($entry) {
            $scope = $entry['source_scope'] ?? 'guidance';
            return "- {$entry['title']} ({$scope}): {$entry['content']}";
        })->join("\n");

        if ($guidanceText === '') {
            $guidanceText = '- No page-specific guidance has been loaded yet. Say that clearly when the user asks for policy or workflow details that are not in the supplied context.';
        }

        $pageSummary = json_encode([
            'path' => $page['path'] ?? '/',
            'route_key' => $page['route_key'] ?? 'unknown',
            'route_name' => $page['route_name'] ?? 'Current page',
            'module_key' => $page['module_key'] ?? '',
            'title' => $page['title'] ?? '',
        ], JSON_UNESCAPED_SLASHES);

        return <<<TEXT
You are the VMECC in-app AI helper. Help signed-in users understand how to use the VMECC operations management system.

Rules:
- Be concise, practical, and specific to the current page when possible.
- Use only the provided page context and guidance for VMECC-specific workflow or policy claims.
- If guidance is missing or incomplete, say that page-specific guidance is not loaded yet and give a safe general navigation answer.
- Do not claim to submit, approve, delete, create, or modify VMECC records. You are advisory only.
- Do not request passwords, API keys, IC numbers, banking details, medical details, or other sensitive personal data.
- Render plain text only.
- {$languageInstruction}

Current page context:
{$pageSummary}

Available VMECC guidance:
{$guidanceText}
TEXT;
    }

    private function languageInstruction(string $responseLanguage): string
    {
        return match ($responseLanguage) {
            'en' => 'Response language: reply in English unless the user explicitly requests another language.',
            'bm' => 'Response language: reply in Bahasa Melayu unless the user explicitly requests another language.',
            default => 'Response language: reply in the same language as the latest user message. If the message mixes English and Bahasa Melayu, use the dominant language or a natural mixed English/BM style.',
        };
    }

    private function cleanPath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }
        $path = '/'.ltrim($path, '/');
        return Str::limit($path, 255, '');
    }

    private function applyScopeFilter($query, string $moduleKey, string $routeKey): void
    {
        $query->where(function ($inner) use ($moduleKey, $routeKey) {
            if ($routeKey !== '') {
                $inner->orWhere('route_key', $routeKey);
            }
            if ($moduleKey !== '') {
                $inner->orWhere('module_key', $moduleKey);
            }
            $inner->orWhere(function ($global) {
                $global->whereNull('module_key')->whereNull('route_key');
            });
        });
    }

    private function applyUsableEntryFilter($query, ?User $user): void
    {
        $query
            ->where('active', true)
            ->where('status', AiHelperKnowledgeEntry::STATUS_ACTIVE)
            ->where('review_status', AiHelperKnowledgeEntry::REVIEW_APPROVED)
            ->where(function ($inner) use ($user) {
                $inner->where('visibility', AiHelperKnowledgeEntry::VISIBILITY_SHARED);
                if ($user) {
                    $inner->orWhere(function ($personal) use ($user) {
                        $personal
                            ->where('visibility', AiHelperKnowledgeEntry::VISIBILITY_PERSONAL)
                            ->where('uploaded_by', $user->id);
                    });
                }
            });
    }

    private function formatChunkGuidance(AiHelperKnowledgeChunk $chunk, string $moduleKey, string $routeKey, string $message): array
    {
        $entry = $chunk->knowledgeEntry;

        return [
            'id' => $entry?->id,
            'chunk_id' => $chunk->id,
            'module_key' => $chunk->module_key,
            'route_key' => $chunk->route_key,
            'title' => $entry?->title ?: 'Knowledge source',
            'content' => $chunk->content,
            'tags' => $entry?->tags ?: [],
            'version' => $entry?->version ?: 1,
            'source_scope' => $this->sourceScope($chunk->module_key, $chunk->route_key, $moduleKey, $routeKey),
            'score' => $this->rankScore($chunk->content, $chunk->module_key, $chunk->route_key, $moduleKey, $routeKey, $message),
        ];
    }

    private function formatEntryGuidance(AiHelperKnowledgeEntry $entry, string $moduleKey, string $routeKey, string $message): array
    {
        $content = Str::limit((string) $entry->content, 1200, '');

        return [
            'id' => $entry->id,
            'module_key' => $entry->module_key,
            'route_key' => $entry->route_key,
            'title' => $entry->title,
            'content' => $content,
            'tags' => $entry->tags ?: [],
            'version' => $entry->version,
            'source_scope' => $this->sourceScope($entry->module_key, $entry->route_key, $moduleKey, $routeKey),
            'score' => $this->rankScore($content, $entry->module_key, $entry->route_key, $moduleKey, $routeKey, $message),
        ];
    }

    private function sourceScope(?string $entryModule, ?string $entryRoute, string $moduleKey, string $routeKey): string
    {
        if ($entryRoute && $entryRoute === $routeKey) {
            return 'Page guidance';
        }
        if ($entryModule && $entryModule === $moduleKey) {
            return 'Module guidance';
        }
        return 'General guidance';
    }

    private function rankScore(string $content, ?string $entryModule, ?string $entryRoute, string $moduleKey, string $routeKey, string $message): int
    {
        $score = 0;
        if ($entryRoute && $entryRoute === $routeKey) {
            $score += 1000;
        } elseif ($entryModule && $entryModule === $moduleKey) {
            $score += 700;
        } elseif (! $entryRoute && ! $entryModule) {
            $score += 250;
        }

        $score += $this->keywordOverlapScore($content, $message);

        return $score;
    }

    private function keywordOverlapScore(string $content, string $message): int
    {
        $terms = collect(preg_split('/[^a-z0-9]+/i', Str::lower($message)) ?: [])
            ->filter(fn (string $term) => Str::length($term) >= 4)
            ->unique()
            ->take(12);

        if ($terms->isEmpty()) {
            return 0;
        }

        $haystack = Str::lower($content);
        return $terms->sum(fn (string $term) => str_contains($haystack, $term) ? 20 : 0);
    }

    private function routeKeyForPath(string $path): string
    {
        $path = strtolower($this->cleanPath($path));
        return match (true) {
            $path === '/' || str_starts_with($path, '/dashboard') => 'dashboard',
            str_starts_with($path, '/inspection') || str_starts_with($path, '/report/inspection') => 'inspection',
            str_starts_with($path, '/leave') || str_starts_with($path, '/staff/leave-management') => 'leave',
            str_starts_with($path, '/overtime') || str_starts_with($path, '/staff/overtime-management') => 'overtime',
            str_starts_with($path, '/payroll') || str_starts_with($path, '/staff/salary-claims') || str_starts_with($path, '/staff/set-salary') => 'payroll',
            str_starts_with($path, '/messages') => 'messages',
            str_starts_with($path, '/settings') => 'settings',
            str_starts_with($path, '/roster') => 'roster',
            str_starts_with($path, '/team') => 'teams',
            str_starts_with($path, '/admin') => 'admin',
            default => trim($path, '/') ?: 'home',
        };
    }

    private function moduleKeyForRouteKey(string $routeKey): string
    {
        if ($routeKey === 'report' || str_starts_with($routeKey, 'report/')) {
            return 'reports';
        }

        return match ($routeKey) {
            'dashboard' => 'dashboard',
            'inspection' => 'inspection',
            'leave' => 'leave',
            'overtime' => 'overtime',
            'payroll' => 'payroll',
            'messages' => 'messages',
            'settings' => 'settings',
            'roster' => 'roster',
            'teams' => 'teams',
            default => '',
        };
    }

    private function titleForRouteKey(string $routeKey): string
    {
        return match ($routeKey) {
            'dashboard' => 'Dashboard',
            'inspection' => 'Inspection',
            'leave' => 'Leave',
            'overtime' => 'Overtime',
            'payroll' => 'Payroll',
            'messages' => 'Messages',
            'settings' => 'Settings',
            'roster' => 'Roster',
            'teams' => 'Teams',
            'admin' => 'Admin',
            default => Str::headline($routeKey ?: 'Current page'),
        };
    }

    private function sanitizeParams(array $params): array
    {
        $clean = [];
        foreach ($params as $key => $value) {
            if (! is_scalar($value) && $value !== null) {
                continue;
            }
            $clean[(string) $key] = Str::limit((string) $value, 120, '');
        }
        return $clean;
    }
}
