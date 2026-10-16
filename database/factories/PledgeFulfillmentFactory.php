<?php

namespace Database\Factories;

use App\Models\Pledge;
use App\Models\PledgeFulfillment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PledgeFulfillment>
 */
class PledgeFulfillmentFactory extends Factory
{
    protected $model = PledgeFulfillment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'pledge_id' => Pledge::factory(),
            'amount_minor' => 50000,
            'method' => PledgeFulfillment::METHOD_CASH,
            'method_detail' => null,
            'received_on' => now()->toDateString(),
            'reference' => null,
            'note' => null,
            'recorded_by' => null,
            'status' => PledgeFulfillment::STATUS_RECORDED,
            'reverses_fulfillment_id' => null,
        ];
    }
}
