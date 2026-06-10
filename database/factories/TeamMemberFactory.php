<?php

namespace Database\Factories;

use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\TeamMember>
 */
class TeamMemberFactory extends Factory
{
    public function definition(): array
    {
        return [
            'team_id'    => Team::factory(),
            'user_id'    => User::factory(),
            'name'       => fake()->name(),
            'role'       => 'tactical response team',
            'is_primary' => false,
            'started_at' => now()->toDateString(),
            'ended_at'   => null,
        ];
    }

    public function ended(): static
    {
        return $this->state(fn (array $attributes) => [
            'ended_at' => now()->subDay()->toDateString(),
        ]);
    }
}
