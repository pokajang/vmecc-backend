<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const UNIQUE_INDEX = 'ra_review_queue_user_role_reason_unique';

    public function up(): void
    {
        $duplicates = DB::table('role_assignment_review_queue')
            ->select('user_id', 'role_id', 'reason')
            ->groupBy('user_id', 'role_id', 'reason')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicates as $row) {
            $duplicateIds = DB::table('role_assignment_review_queue')
                ->where('user_id', $row->user_id)
                ->where('role_id', $row->role_id)
                ->where('reason', $row->reason)
                ->orderBy('id')
                ->pluck('id')
                ->skip(1)
                ->values()
                ->all();

            if (! empty($duplicateIds)) {
                DB::table('role_assignment_review_queue')
                    ->whereIn('id', $duplicateIds)
                    ->delete();
            }
        }

        Schema::table('role_assignment_review_queue', function (Blueprint $table) {
            $table->unique(['user_id', 'role_id', 'reason'], self::UNIQUE_INDEX);
        });
    }

    public function down(): void
    {
        Schema::table('role_assignment_review_queue', function (Blueprint $table) {
            $table->dropUnique(self::UNIQUE_INDEX);
        });
    }
};
