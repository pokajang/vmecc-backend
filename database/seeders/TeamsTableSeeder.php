<?php

namespace Database\Seeders;

use App\Models\Team;
use Illuminate\Database\Seeder;

class TeamsTableSeeder extends Seeder
{
    /**
     * Seed the default fixed teams (no members by default).
     */
    public function run(): void
    {
        $teams = [
            ['name' => 'Alpha', 'status' => 'On Duty'],
            ['name' => 'Bravo', 'status' => 'On Duty'],
            ['name' => 'Charlie', 'status' => 'On Leave'],
            ['name' => 'Delta', 'status' => 'On Duty'],
        ];

        foreach ($teams as $team) {
            Team::firstOrCreate(['name' => $team['name']], [
                'status' => $team['status'],
            ]);
        }
    }
}
