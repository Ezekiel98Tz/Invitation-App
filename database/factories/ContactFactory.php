<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Contact>
 */
class ContactFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $email = $this->faker->safeEmail();
        $phone = $this->faker->e164PhoneNumber();

        return [
            'user_id' => User::factory(),
            'name' => $this->faker->name(),
            'email' => $email,
            'phone' => $phone,
            'normalized_email' => Str::lower($email),
            'normalized_phone' => preg_replace('/\D+/', '', $phone),
            'notes' => null,
        ];
    }
}
