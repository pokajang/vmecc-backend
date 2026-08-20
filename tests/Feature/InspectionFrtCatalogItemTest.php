<?php

namespace Tests\Feature;

use App\Models\InspectionFireTruck;
use App\Models\InspectionFrtCatalogItem;
use Database\Seeders\InspectionFireTruckCatalogSeeder;
use Database\Seeders\InspectionFrtCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InspectionFrtCatalogItemTest extends TestCase
{
    use RefreshDatabase;

    public function test_frt_catalog_items_persist_metadata_and_belong_to_a_fire_truck(): void
    {
        $truck = InspectionFireTruck::query()->create([
            'plate_no' => 'FRT-001',
            'normalized_plate_no' => 'FRT001',
            'source' => 'seed',
            'is_active' => true,
        ]);

        $item = InspectionFrtCatalogItem::query()->create([
            'fire_truck_id' => $truck->id,
            'checklist_kind' => 'daily',
            'compartment' => 'Locker A',
            'name' => 'Rescue Rope',
            'normalized_name' => 'rescue rope',
            'quantity' => '2',
            'metadata' => ['workbookRowNumber' => '14'],
            'source' => 'seed',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $this->assertSame(['workbookRowNumber' => '14'], $item->fresh()->metadata);
        $this->assertTrue($truck->catalogItems->contains($item));
        $this->assertSame($truck->id, $item->truck->id);
    }

    public function test_frt_workbook_catalog_seeds_daily_and_one_off_rows(): void
    {
        $this->seed([InspectionFireTruckCatalogSeeder::class, InspectionFrtCatalogSeeder::class]);

        $truck = InspectionFireTruck::query()->where('normalized_plate_no', 'AJG9555')->firstOrFail();
        $this->assertSame(92, $truck->catalogItems()->where('checklist_kind', 'daily')->count());
        $this->assertSame(46, $truck->catalogItems()->where('checklist_kind', 'one-off')->count());
        $mileage = $truck->catalogItems()->where('name', 'MILEAGE (ODOMETER)')->firstOrFail();
        $this->assertSame('reading', $mileage->metadata['rowKind']);
    }
}
