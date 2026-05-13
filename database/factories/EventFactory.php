<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Event>
 */
class EventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'title' => $this->faker->sentence(3),
            'description' => $this->faker->optional()->paragraph(),
            'event_start' => Carbon::now()->addDays(7),
            'timezone' => 'Africa/Dar_es_Salaam',
            'venue' => $this->faker->company(),
            'capacity' => null,
            'settings' => [
                'allow_plus_ones' => false,
                'custom_questions' => [],
                'enable_waitlist' => false,
            ],
        ];
    }
}
