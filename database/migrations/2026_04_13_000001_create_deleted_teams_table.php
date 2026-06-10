<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deleted_teams', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('status')->nullable();
            $table->string('image_url')->nullable();
            $table->unsignedBigInteger('lead_id')->nullable();
            $table->string('lead_name')->nullable();
            $table->json('members_snapshot'); // active members at time of deletion
            $table->unsignedBigInteger('deleted_by_user_id')->nullable();
            $table->timestamp('deleted_at');

            $table->index('deleted_by_user_id');
            $table->index('deleted_at');
            $table->foreign('deleted_by_user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deleted_teams');
    }
};
