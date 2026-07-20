<?php

namespace Tests\Unit;

use App\Services\InspectionReports\InspectionReportLocationService;
use Tests\TestCase;

class InspectionReportLocationServiceTest extends TestCase
{
    public function test_it_normalizes_deduplicates_and_naturally_sorts_locations(): void
    {
        $derived = app(InspectionReportLocationService::class)->derive([
            ['zone' => '1', 'mainLocation' => 'Area 10', 'subLocation' => 'Workshop'],
            ['zone' => '1', 'mainLocation' => 'Area 2', 'subLocation' => 'Reception'],
            ['zone' => ' 1 ', 'mainLocation' => ' area 2 ', 'subLocation' => ' reception '],
        ]);

        $this->assertSame('2 locations across 2 areas', $derived['summary']);
        $this->assertSame([
            'Zone 1 > Area 2 > Reception',
            'Zone 1 > Area 10 > Workshop',
        ], $derived['paths']);
        $this->assertCount(2, $derived['locations']);
    }

    public function test_it_summarizes_multiple_sublocations_under_one_area(): void
    {
        $derived = app(InspectionReportLocationService::class)->derive([
            ['zone' => 'Zone A', 'mainLocation' => 'Main Store', 'subLocation' => 'Rack 1'],
            ['zone' => 'Zone A', 'mainLocation' => 'Main Store', 'subLocation' => 'Rack 2'],
        ]);

        $this->assertSame('Zone A > Main Store · 2 locations', $derived['summary']);
        $this->assertSame('Main Store', $derived['mainLocation']);
        $this->assertSame('', $derived['subLocation']);
    }

    public function test_it_uses_a_neutral_summary_for_incomplete_location_components(): void
    {
        $derived = app(InspectionReportLocationService::class)->derive([
            ['zone' => '1', 'mainLocation' => 'Main Store', 'subLocation' => 'Rack 1'],
            ['zone' => '1', 'mainLocation' => '', 'subLocation' => 'External Bay'],
            ['zone' => '', 'mainLocation' => '', 'subLocation' => ''],
        ]);

        $this->assertSame('2 inspection locations', $derived['summary']);
        $this->assertSame('', $derived['mainLocation']);
    }
}
