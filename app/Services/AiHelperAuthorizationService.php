<?php

namespace App\Services;

use App\Models\AiHelperKnowledgeEntry;
use App\Models\AiHelperThread;
use App\Models\User;
use Illuminate\Support\Str;

class AiHelperAuthorizationService
{
    public function __construct(private readonly AssignmentAuthorizationService $authorization)
    {
    }

    public function isSystemAdministrator(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        $roleNames = $this->authorization->getActiveRoleNames($user)
            ->map(fn (string $role) => Str::lower(trim($role)));

        return $roleNames->contains('system administrator')
            || $roleNames->contains('system admin')
            || $this->authorization->hasPermission($user, '*');
    }

    public function ownsThread(User $user, AiHelperThread $thread): bool
    {
        return (int) $thread->user_id === (int) $user->id;
    }

    public function canManageKnowledge(User $user, AiHelperKnowledgeEntry $entry): bool
    {
        return (int) $entry->uploaded_by === (int) $user->id || $this->isSystemAdministrator($user);
    }
}
