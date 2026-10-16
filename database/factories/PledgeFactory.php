<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\Pledge;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Pledge>
 */
class PledgeFactory extends Factory
{
    protected $model = Pledge::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'event_id' => Event::factory(),
            'guest_id' => null,
            'contact_id' => null,
            'pledger_name' => $this->faker->name(),
            'pledger_email' => $this->faker->safeEmail(),
            'pledger_phone' => $this->faker->e164PhoneNumber(),
            'currency' => 'TZS',
            'pledged_amount_minor' => 100000,
            'note' => null,
            'source' => Pledge::SOURCE_MANUAL,
            'status' => Pledge::STATUS_ACTIVE,
            'created_by' => null,
        ];
    }

    public function cancelled(): static
    {
        return $this->state(fn () => ['status' => Pledge::STATUS_CANCELLED]);
    }

    public function forEvent(Event $event): static
    {
        return $this->state(fn () => [
            'user_id' => $event->user_id,
            'event_id' => $event->id,
        ]);
    }
}
