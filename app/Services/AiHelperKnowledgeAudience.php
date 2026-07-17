<?php

namespace App\Services;

final readonly class AiHelperKnowledgeAudience
{
    /**
     * @param  array<int, string>  $roleNames
     * @param  array<int, string>  $permissionNames
     * @param  array<string, bool>  $moduleStates
     */
    public function __construct(
        public ?int $userId,
        public bool $systemAdministrator,
        public array $roleNames,
        public array $permissionNames,
        public array $moduleStates,
        public ?string $routeKey,
        public ?string $moduleKey,
    ) {}
}
