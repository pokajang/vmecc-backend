<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_media_leases', function (Blueprint $table): void {
            $table->id();
            $table->uuid('lease_uid')->unique();
            $table->foreignId('report_media_id')->unique()->constrained('report_media')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('context_key', 190)->default('');
            $table->timestamp('expires_at')->index();
            $table->timestamp('absolute_expires_at')->index();
            $table->timestamp('renewed_at')->nullable();
            $table->timestamps();
        });

        $now = now();
        DB::table('report_media')
            ->where('created_at', '>=', $now->copy()->subDays(30))
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('report_media_links')
                    ->whereColumn('report_media_links.report_media_id', 'report_media.id');
            })
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($now): void {
                foreach ($rows as $row) {
                    DB::table('report_media_leases')->insertOrIgnore([
                        'lease_uid' => (string) Str::uuid(),
                        'report_media_id' => $row->id,
                        'user_id' => $row->user_id,
                        'context_key' => 'legacy-unlinked-media',
                        'expires_at' => $now->copy()->addDays(7),
                        'absolute_expires_at' => $now->copy()->addDays(30),
                        'renewed_at' => $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_media_leases');
    }
};
