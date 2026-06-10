<?php

namespace Tests\Unit;

use App\Models\Holiday;
use App\Models\User;
use App\Services\HolidayResolver;
use App\Services\OvertimeDateClassifier;
use App\Services\WorkingDayCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HolidayRuleServicesTest extends TestCase
{
    use RefreshDatabase;

    public function test_holiday_resolver_includes_national_and_matching_state_holidays(): void
    {
        $user = User::factory()->create(['state' => 'Perak']);

        Holiday::query()->create([
            'name' => 'National Day',
            'date' => '2026-08-31',
            'year' => 2026,
            'scope' => 'National',
            'state' => 'All States',
            'is_default_national' => true,
            'fixed_holiday_key' => 'national-day',
        ]);
        Holiday::query()->create([
            'name' => 'Perak Holiday',
            'date' => '2026-04-15',
            'year' => 2026,
            'scope' => 'State',
            'state' => 'Perak',
            'is_default_national' => false,
            'fixed_holiday_key' => null,
        ]);
        Holiday::query()->create([
            'name' => 'Selangor Holiday',
            'date' => '2026-04-16',
            'year' => 2026,
            'scope' => 'State',
            'state' => 'Selangor',
            'is_default_national' => false,
            'fixed_holiday_key' => null,
        ]);

        $resolver = app(HolidayResolver::class);
        $dates = $resolver->getApplicableHolidayDatesForUser($user, '2026-04-01', '2026-09-30');

        $this->assertTrue($dates->contains('2026-04-15'));
        $this->assertTrue($dates->contains('2026-08-31'));
        $this->assertFalse($dates->contains('2026-04-16'));
    }

    public function test_missing_employee_state_falls_back_to_national_only(): void
    {
        $user = User::factory()->create(['state' => null]);

        Holiday::query()->create([
            'name' => 'National Day',
            'date' => '2026-08-31',
            'year' => 2026,
            'scope' => 'National',
            'state' => 'All States',
            'is_default_national' => true,
            'fixed_holiday_key' => 'national-day',
        ]);
        Holiday::query()->create([
            'name' => 'Perak Holiday',
            'date' => '2026-04-15',
            'year' => 2026,
            'scope' => 'State',
            'state' => 'Perak',
            'is_default_national' => false,
            'fixed_holiday_key' => null,
        ]);

        $resolver = app(HolidayResolver::class);
        $dates = $resolver->getApplicableHolidayDatesForUser($user, '2026-01-01', '2026-12-31');

        $this->assertTrue($dates->contains('2026-08-31'));
        $this->assertFalse($dates->contains('2026-04-15'));
    }

    public function test_working_day_calculator_excludes_weekends_and_holidays(): void
    {
        $user = User::factory()->create(['state' => 'Perak']);

        Holiday::query()->create([
            'name' => 'Labour Day',
            'date' => '2026-05-01',
            'year' => 2026,
            'scope' => 'National',
            'state' => 'All States',
            'is_default_national' => true,
            'fixed_holiday_key' => 'labour-day',
        ]);

        $calculator = app(WorkingDayCalculator::class);
        $days = $calculator->computeLeaveDays($user, '2026-05-01', '2026-05-04', 'shift-start', 'shift-end');

        // Fri holiday + weekend + Mon workday => 1 day
        $this->assertSame(1.0, $days);
    }

    public function test_overtime_classifier_prioritizes_public_holiday_over_weekday_weekend(): void
    {
        $user = User::factory()->create(['state' => 'Perak']);

        Holiday::query()->create([
            'name' => 'Special Holiday',
            'date' => '2026-04-13',
            'year' => 2026,
            'scope' => 'National',
            'state' => 'All States',
            'is_default_national' => false,
            'fixed_holiday_key' => null,
        ]);

        $classifier = app(OvertimeDateClassifier::class);

        $this->assertSame('publicHoliday', $classifier->classify($user, '2026-04-13'));
        $this->assertSame('weekend', $classifier->classify($user, '2026-04-12'));
        $this->assertSame('weekday', $classifier->classify($user, '2026-04-14'));
    }
}

