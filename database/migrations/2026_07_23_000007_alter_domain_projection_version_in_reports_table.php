<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = Schema::getConnection();

        if ($connection->getDriverName() === 'pgsql') {
            $connection->statement(
                'ALTER TABLE "reports" ALTER COLUMN "domain_projection_version" TYPE INTEGER USING "domain_projection_version"::integer'
            );

            return;
        }

        Schema::table('reports', function (Blueprint $table): void {
            $table->unsignedInteger('domain_projection_version')->nullable()->change();
        });
    }

    public function down(): void
    {
        $connection = Schema::getConnection();

        if ($connection->getDriverName() === 'pgsql') {
            $connection->statement(
                'ALTER TABLE "reports" ALTER COLUMN "domain_projection_version" TYPE SMALLINT USING "domain_projection_version"::smallint'
            );

            return;
        }

        Schema::table('reports', function (Blueprint $table): void {
            $table->unsignedSmallInteger('domain_projection_version')->nullable()->change();
        });
    }
};
