<?php

namespace Tests\Feature\Api;

use App\Models\Event;
use App\Models\Pledge;
use App\Models\PledgeFulfillment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Regression coverage for importing ORDINARY human-readable amounts (with
 * thousands separators, decimal points and currency prefixes) rather than
 * requiring internal minor units, plus currency defaults and the optional
 * supplied-outstanding-balance consistency check.
 */
class PledgeImportAmountTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        return User::factory()->create();
    }

    private function event(User $owner, ?string $currency = null): Event
    {
        $settings = ['capabilities' => ['contributions' => true]];

        if ($currency !== null) {
            $settings['currency'] = $currency;
        }

        return Event::factory()->create([
            'user_id' => $owner->id,
            'event_start' => now()->addDays(7),
            'settings' => $settings,
        ]);
    }

    /**
     * @param  list<string>  $headers
     * @param  list<list<mixed>>  $rows
     */
    private function csv(array $headers, array $rows): string
    {
        $escape = static fn ($value): string => str_contains((string) $value, ',')
            ? '"'.$value.'"'
            : (string) $value;

        $lines = [implode(',', $headers)];

        foreach ($rows as $row) {
            $lines[] = implode(',', array_map($escape, $row));
        }

        return implode("\n", $lines);
    }

    private function upload(Event $event, User $user, string $content): int
    {
        return (int) $this->actingAs($user)
            ->withHeaders(['Accept' => 'application/json'])
            ->post(route('api.events.pledges.import.upload', ['event' => $event->id]), [
                'file' => UploadedFile::fake()->createWithContent('pledges.csv', $content),
            ])
            ->assertStatus(201)
            ->json('batch.id');
    }

    public function test_ordinary_amounts_with_separators_and_currency_prefixes_are_accepted(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);

        $content = $this->csv(
            ['Name', 'Phone', 'Amount', 'Paid', 'Balance'],
            [
                ['Alice', '+255700000001', 'TZS 150,000', '50,000', '100,000'],
            ]
        );

        $batchId = $this->upload($event, $owner, $content);

        $mapping = [
            'pledger_name' => 'Name',
            'pledger_phone' => 'Phone',
            'pledged_amount' => 'Amount',
            'contributed_amount' => 'Paid',
            'outstanding_balance' => 'Balance',
        ];

        $this->actingAs($owner)->postJson(
            route('api.events.pledges.import.preview', [$event, $batchId]),
            ['mapping' => $mapping, 'mode' => 'create_only', 'default_currency' => 'TZS'],
        )->assertOk()->assertJsonPath('rows.0.action', 'create');

        $this->actingAs($owner)->postJson(
            route('api.events.pledges.import.confirm', [$event, $batchId]),
            ['mapping' => $mapping, 'mode' => 'create_only', 'default_currency' => 'TZS'],
        )->assertOk()->assertJsonPath('counts.created', 1);

        $pledge = Pledge::where('event_id', $event->id)->where('pledger_name', 'Alice')->firstOrFail();
        $this->assertSame('TZS', $pledge->currency);
        // 150,000 ordinary → 15,000,000 minor units (TZS, 2 decimals).
        $this->assertSame(15000000, (int) $pledge->pledged_amount_minor);

        // The reported contribution became a fulfillment of 50,000 → 5,000,000.
        $fulfillment = PledgeFulfillment::where('pledge_id', $pledge->id)->firstOrFail();
        $this->assertSame(5000000, (int) $fulfillment->amount_minor);
    }

    public function test_missing_currency_uses_the_configured_event_currency(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner, currency: 'TZS');

        $content = $this->csv(['Name', 'Amount'], [['Bob', '2000.50']]);
        $batchId = $this->upload($event, $owner, $content);

        $mapping = ['pledger_name' => 'Name', 'pledged_amount' => 'Amount'];

        $this->actingAs($owner)->postJson(
            route('api.events.pledges.import.confirm', [$event, $batchId]),
            ['mapping' => $mapping, 'mode' => 'create_only'],
        )->assertOk()->assertJsonPath('counts.created', 1);

        $pledge = Pledge::where('event_id', $event->id)->firstOrFail();
        $this->assertSame('TZS', $pledge->currency);
        $this->assertSame(200050, (int) $pledge->pledged_amount_minor);
    }

    public function test_amount_with_too_many_decimals_is_rejected_with_a_row_specific_error(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);

        $content = $this->csv(['Name', 'Amount'], [['Cara', '100.999']]);
        $batchId = $this->upload($event, $owner, $content);

        $row = $this->actingAs($owner)->postJson(
            route('api.events.pledges.import.preview', [$event, $batchId]),
            ['mapping' => ['pledger_name' => 'Name', 'pledged_amount' => 'Amount'], 'default_currency' => 'USD'],
        )->assertOk();

        $this->assertSame('failed', $row->json('rows.0.action'));
        $this->assertArrayHasKey('pledged_amount', (array) $row->json('rows.0.errors'));

        // Preview never wrote anything.
        $this->assertSame(0, Pledge::where('event_id', $event->id)->count());
    }

    public function test_supplied_outstanding_balance_mismatch_is_flagged_and_not_stored(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);

        $content = $this->csv(
            ['Name', 'Amount', 'Paid', 'Balance'],
            [['Dora', '100', '40', '999']]
        );
        $batchId = $this->upload($event, $owner, $content);

        if (! extension_loaded('zip')) {
            // CSV path needs no zip; this guard is only illustrative.
        }

        $row = $this->actingAs($owner)->postJson(
            route('api.events.pledges.import.preview', [$event, $batchId]),
            [
                'mapping' => [
                    'pledger_name' => 'Name',
                    'pledged_amount' => 'Amount',
                    'contributed_amount' => 'Paid',
                    'outstanding_balance' => 'Balance',
                ],
                'default_currency' => 'USD',
            ],
        )->assertOk();

        $this->assertSame('failed', $row->json('rows.0.action'));
        $this->assertArrayHasKey('outstanding_balance', (array) $row->json('rows.0.errors'));
        $this->assertSame(0, Pledge::where('event_id', $event->id)->count());
    }
}
