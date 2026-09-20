<?php

namespace Database\Factories;

use App\Modules\Organization\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Team>
 */
class TeamFactory extends Factory
{
    protected $model = Team::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'lead_user_id' => null,
        ];
    }
}
