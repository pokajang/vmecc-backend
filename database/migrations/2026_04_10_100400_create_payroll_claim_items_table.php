<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_claim_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_claim_id')->constrained('payroll_claims')->cascadeOnDelete();
            $table->unsignedInteger('line_no')->default(1);
            $table->string('item_type')->nullable();
            $table->string('title')->nullable();
            $table->date('claim_date')->nullable();
            $table->decimal('amount', 14, 2)->default(0);
            $table->text('notes')->nullable();
            $table->json('item_meta')->nullable();
            $table->foreignId('attachment_id')->nullable()->constrained('workflow_attachments')->nullOnDelete();
            $table->timestamps();

            $table->index(['payroll_claim_id', 'line_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_claim_items');
    }
};
