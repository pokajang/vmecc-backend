<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_claims', function (Blueprint $table) {
            $table->timestamp('paid_at')->nullable()->after('payment_date');
            $table->foreignId('paid_by_user_id')->nullable()->after('paid_at')->constrained('users')->nullOnDelete();
            $table->string('payment_reference')->nullable()->after('paid_by_user_id');
            $table->text('payment_note')->nullable()->after('payment_reference');

            $table->index('paid_at');
            $table->index('paid_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_claims', function (Blueprint $table) {
            $table->dropIndex(['paid_at']);
            $table->dropIndex(['paid_by_user_id']);
            $table->dropForeign(['paid_by_user_id']);
            $table->dropColumn(['paid_at', 'paid_by_user_id', 'payment_reference', 'payment_note']);
        });
    }
};
