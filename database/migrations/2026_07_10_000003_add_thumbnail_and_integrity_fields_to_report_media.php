<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_media', function (Blueprint $table) {
            $table->string('thumbnail_path', 500)->nullable()->after('storage_path');
            $table->unsignedBigInteger('thumbnail_size_bytes')->nullable()->after('size_bytes');
            $table->unsignedInteger('thumbnail_width')->nullable()->after('width');
            $table->unsignedInteger('thumbnail_height')->nullable()->after('height');
            $table->char('checksum_sha256', 64)->nullable()->after('thumbnail_height')->index();
            $table->char('thumbnail_checksum_sha256', 64)->nullable()->after('checksum_sha256');
        });
    }

    public function down(): void
    {
        Schema::table('report_media', function (Blueprint $table) {
            $table->dropIndex(['checksum_sha256']);
            $table->dropColumn([
                'thumbnail_path',
                'thumbnail_size_bytes',
                'thumbnail_width',
                'thumbnail_height',
                'checksum_sha256',
                'thumbnail_checksum_sha256',
            ]);
        });
    }
};
