<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('holidays', function (Blueprint $table) {
            $table->id();

            // Human-readable name, e.g. "New Year's Day"
            $table->string('name');

            // ISO 8601 date string stored as date column
            $table->date('date');

            // Derived from date for fast filtering; kept in sync on write
            $table->unsignedSmallInteger('year');

            // 'National' or 'State'
            $table->string('scope')->default('National');

            // Populated only when scope = 'State', e.g. 'Selangor'
            $table->string('state')->default('All States');

            // True when this row was created from a fixed national template
            $table->boolean('is_default_national')->default(false);

            // Stable natural key for fixed nationals e.g. 'new-years-day'
            // NULL for ad-hoc additional holidays
            $table->string('fixed_holiday_key')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Prevent duplicate nationals per year (upsert key)
            $table->unique(['fixed_holiday_key', 'year'], 'holidays_fixed_key_year_unique');

            // Prevent exact duplicate ad-hoc entries
            $table->unique(['name', 'date', 'scope', 'state'], 'holidays_adhoc_unique');

            $table->index('year');
            $table->index('scope');
            $table->index(['scope', 'state']);
        });

        Schema::create('holiday_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('holiday_id')->constrained('holidays')->cascadeOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            // 'created', 'updated', 'deleted'
            $table->string('action');
            $table->json('changes')->nullable();
            $table->timestamps();

            $table->index(['holiday_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('holiday_histories');
        Schema::dropIfExists('holidays');
    }
};
