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
        Schema::table('inspection_fire_extinguishers', function (Blueprint $table) {
            $table->string('active_identity_key', 64)->nullable()->after('barcode_no');
        });

        $seen = [];
        DB::table('inspection_fire_extinguishers')
            ->where('is_active', true)
            ->where('source', 'custom')
            ->orderBy('id')
            ->select(['id', 'main_location_name', 'sub_location_name', 'id_loc_no', 'barcode_no'])
            ->get()
            ->each(function ($row) use (&$seen): void {
                $mainLocation = $this->identityPart($row->main_location_name);
                $subLocation = $this->identityPart($row->sub_location_name);
                $idLocNo = $this->identityPart($row->id_loc_no);
                $barcodeNo = $this->identityPart($row->barcode_no);

                if ($mainLocation === '' || ($idLocNo === '' && $barcodeNo === '')) {
                    return;
                }

                $identityKey = hash('sha256', implode('|', [
                    $mainLocation,
                    $subLocation,
                    $idLocNo,
                    $barcodeNo,
                ]));

                if (isset($seen[$identityKey])) {
                    throw new RuntimeException(
                        "Duplicate active fire extinguisher identity found for rows {$seen[$identityKey]} and {$row->id}. Resolve duplicates before running this migration."
                    );
                }

                $seen[$identityKey] = $row->id;
                DB::table('inspection_fire_extinguishers')
                    ->where('id', $row->id)
                    ->update(['active_identity_key' => $identityKey]);
            });

        Schema::table('inspection_fire_extinguishers', function (Blueprint $table) {
            $table->unique('active_identity_key', 'inspection_fire_extinguishers_active_identity_unique');
        });
    }

    public function down(): void
    {
        Schema::table('inspection_fire_extinguishers', function (Blueprint $table) {
            $table->dropUnique('inspection_fire_extinguishers_active_identity_unique');
            $table->dropColumn('active_identity_key');
        });
    }

    private function identityPart(mixed $value): string
    {
        return Str::of(str_replace(["CO\u{00B2}", "CO\u{FFFD}"], 'CO2', (string) $value))
            ->squish()
            ->lower()
            ->toString();
    }
};
