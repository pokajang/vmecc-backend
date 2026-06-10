<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserRoleAssignment;
use App\Services\RoleCatalog;
use App\Services\WorkflowNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class WorkflowNotificationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_emit_normalizes_role_recipient_and_detail_route_key_for_overtime(): void
    {
        $owner = User::factory()->create();
        $reviewer = User::factory()->create();
        $role = Role::firstOrCreate(['name' => 'Contract Manager', 'guard_name' => 'web']);
        UserRoleAssignment::create([
            'user_id' => $reviewer->id,
            'role_id' => $role->id,
            'scope_type' => RoleCatalog::GLOBAL,
            'is_primary' => true,
        ]);

        $service = app(WorkflowNotificationService::class);
        $notification = $service->emit(
            module: 'overtime',
            eventType: 'submitted',
            recordType: 'overtime',
            recordId: 88,
            recordDisplayId: 'OT-2026-088',
            ownerUserId: (int) $owner->id,
            actor: ['userId' => $owner->id, 'name' => $owner->name, 'email' => $owner->email],
            targetRoles: ['contract manager'],
            actionRequired: true,
            metadata: ['nextActionRole' => 'Contract Manager', 'status' => 'Pending'],
        );

        $this->assertContains((int) $reviewer->id, $notification->recipient_user_ids);
        $this->assertSame("{$owner->id}::88", data_get($notification->metadata, 'detailRouteKey'));

        $reviewerView = $service->forViewer((int) $reviewer->id, false, false, 20, 'overtime')->first();
        $this->assertNotNull($reviewerView);
        $this->assertTrue((bool) data_get($reviewerView, 'actionRequiredForViewer'));
        $this->assertSame("{$owner->id}::88", data_get($reviewerView, 'metadata.detailRouteKey'));

        $ownerView = $service->forViewer((int) $owner->id, false, false, 20, 'overtime')->first();
        $this->assertNotNull($ownerView);
        $this->assertFalse((bool) data_get($ownerView, 'actionRequiredForViewer'));
    }

    public function test_emit_targets_only_active_role_assignments_for_role_recipients(): void
    {
        $owner = User::factory()->create();
        $activeReviewer = User::factory()->create(['status' => 'active']);
        $expiredReviewer = User::factory()->create(['status' => 'active']);
        $inactiveReviewer = User::factory()->create(['status' => 'inactive']);

        $role = Role::firstOrCreate(['name' => 'Contract Manager', 'guard_name' => 'web']);
        UserRoleAssignment::create([
            'user_id' => $activeReviewer->id,
            'role_id' => $role->id,
            'scope_type' => RoleCatalog::GLOBAL,
            'is_primary' => true,
            'start_date' => now()->subDay(),
            'end_date' => now()->addDay(),
        ]);
        UserRoleAssignment::create([
            'user_id' => $expiredReviewer->id,
            'role_id' => $role->id,
            'scope_type' => RoleCatalog::GLOBAL,
            'is_primary' => true,
            'start_date' => now()->subDays(10),
            'end_date' => now()->subDay(),
        ]);
        UserRoleAssignment::create([
            'user_id' => $inactiveReviewer->id,
            'role_id' => $role->id,
            'scope_type' => RoleCatalog::GLOBAL,
            'is_primary' => true,
            'start_date' => now()->subDay(),
            'end_date' => now()->addDay(),
        ]);

        $service = app(WorkflowNotificationService::class);
        $notification = $service->emit(
            module: 'salary',
            eventType: 'submitted',
            recordType: 'payroll_claim',
            recordId: 91,
            recordDisplayId: 'SC-2026-091',
            ownerUserId: (int) $owner->id,
            actor: ['userId' => $owner->id, 'name' => $owner->name, 'email' => $owner->email],
            targetRoles: ['Contract Manager'],
            actionRequired: true,
            metadata: ['nextActionRole' => 'Contract Manager', 'status' => 'Pending'],
            excludeOwner: true,
        );

        $this->assertContains((int) $activeReviewer->id, $notification->recipient_user_ids);
        $this->assertNotContains((int) $expiredReviewer->id, $notification->recipient_user_ids);
        $this->assertNotContains((int) $inactiveReviewer->id, $notification->recipient_user_ids);
    }

    public function test_system_administrator_mark_all_read_matches_visible_notifications(): void
    {
        $owner = User::factory()->create();
        $admin = User::factory()->create(['status' => 'active']);
        $role = Role::firstOrCreate(['name' => 'System Administrator', 'guard_name' => 'web']);
        UserRoleAssignment::create([
            'user_id' => $admin->id,
            'role_id' => $role->id,
            'scope_type' => RoleCatalog::GLOBAL,
            'is_primary' => true,
        ]);

        $service = app(WorkflowNotificationService::class);
        $service->emit(
            module: 'overtime',
            eventType: 'approved',
            recordType: 'overtime',
            recordId: 92,
            recordDisplayId: 'OT-2026-092',
            ownerUserId: (int) $owner->id,
            actor: ['userId' => $owner->id, 'name' => $owner->name, 'email' => $owner->email],
            targetRoles: [],
            targetUserIds: [],
            actionRequired: false,
            excludeOwner: true,
        );

        $adminView = $service->forViewer((int) $admin->id, false, false, 20)->first();
        $this->assertNotNull($adminView);
        $this->assertFalse((bool) data_get($adminView, 'read'));
        $this->assertSame(1, $service->unreadCount((int) $admin->id));

        $service->markAllRead((int) $admin->id);

        $adminViewAfterRead = $service->forViewer((int) $admin->id, false, false, 20)->first();
        $this->assertNotNull($adminViewAfterRead);
        $this->assertTrue((bool) data_get($adminViewAfterRead, 'read'));
        $this->assertSame(0, $service->unreadCount((int) $admin->id));
    }
}
