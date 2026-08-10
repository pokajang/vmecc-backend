<?php

namespace Database\Seeders;

use App\Models\TeamMember;
use App\Models\User;
use App\Models\UserRoleAssignment;
use App\Models\UserSession;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class LiveUatUsersCleanupSeeder extends Seeder
{
    public function run(): void
    {
        $personas = config('live_uat.personas', []);
        DB::transaction(function () use ($personas): void {
            foreach ($personas as $persona) {
                $email = strtolower(trim((string) ($persona['email'] ?? '')));
                $expectedName = trim((string) ($persona['name'] ?? ''));
                if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new RuntimeException('Every cleanup target requires a valid configured email.');
                }

                $user = User::withTrashed()->where('email', $email)->first();
                if (! $user) {
                    continue;
                }
                if ($user->name !== $expectedName || ! str_starts_with($user->name, '[Live UAT] ')) {
                    throw new RuntimeException("Refusing to remove non-UAT user: {$email}");
                }

                $user->tokens()->delete();
                UserSession::query()->where('user_id', $user->id)->delete();
                TeamMember::query()->where('user_id', $user->id)->delete();
                UserRoleAssignment::query()->where('user_id', $user->id)->delete();
                $user->syncRoles([]);
                $user->forceFill([
                    'status' => 'Inactive',
                    'remember_token' => null,
                ])->save();
                if (! $user->trashed()) {
                    $user->delete();
                }
            }
        });
    }
}
