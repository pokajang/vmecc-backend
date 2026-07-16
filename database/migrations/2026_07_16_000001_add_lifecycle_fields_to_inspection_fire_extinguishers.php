<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inspection_fire_extinguishers', function (Blueprint $table): void {
            $table->string('lifecycle_status', 32)->default('active')->after('is_active');
            $table->foreignId('updated_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            $table->timestamp('out_of_service_at')->nullable();
            $table->foreignId('out_of_service_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('out_of_service_reason')->nullable();
            $table->timestamp('retired_at')->nullable();
            $table->foreignId('retired_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('retirement_reason')->nullable();
            $table->timestamp('restored_at')->nullable();
            $table->foreignId('restored_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('lock_version')->default(1);
            $table->index(['lifecycle_status', 'is_active'], 'inspection_fire_extinguishers_lifecycle_idx');
        });

        DB::table('inspection_fire_extinguishers')
            ->where('is_active', false)
            ->update(['lifecycle_status' => 'retired']);
    }

    public function down(): void
    {
        Schema::table('inspection_fire_extinguishers', function (Blueprint $table): void {
            $table->dropIndex('inspection_fire_extinguishers_lifecycle_idx');
            $table->dropConstrainedForeignId('updated_by');
            $table->dropConstrainedForeignId('out_of_service_by');
            $table->dropConstrainedForeignId('retired_by');
            $table->dropConstrainedForeignId('restored_by');
            $table->dropColumn([
                'lifecycle_status', 'out_of_service_at', 'out_of_service_reason',
                'retired_at', 'retirement_reason', 'restored_at', 'lock_version',
            ]);
        });
    }
};
