<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\User;
use App\Services\ContactResolution;
use App\Services\ContactResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactResolverTest extends TestCase
{
    use RefreshDatabase;

    private function resolver(): ContactResolver
    {
        return app(ContactResolver::class);
    }

    public function test_creates_a_contact_when_no_identifier_matches(): void
    {
        $owner = User::factory()->create();

        $resolution = $this->resolver()->resolve($owner->id, 'Ada', 'ada@example.test', '+255700000001');

        $this->assertSame(ContactResolution::CREATED, $resolution->status);
        $this->assertNotNull($resolution->contact);
        $this->assertSame($owner->id, $resolution->contact->user_id);
        $this->assertSame('ada@example.test', $resolution->contact->normalized_email);
        $this->assertSame('255700000001', $resolution->contact->normalized_phone);
        $this->assertSame(1, Contact::count());
    }

    public function test_email_matching_is_normalized_and_takes_precedence(): void
    {
        $owner = User::factory()->create();
        $existing = Contact::factory()->create([
            'user_id' => $owner->id,
            'email' => 'ada@example.test',
            'normalized_email' => 'ada@example.test',
            'phone' => null,
            'normalized_phone' => null,
        ]);

        $resolution = $this->resolver()->resolve($owner->id, 'Ada', '  ADA@Example.TEST ', null);

        $this->assertSame(ContactResolution::RESOLVED, $resolution->status);
        $this->assertSame($existing->id, $resolution->contactId());
        $this->assertSame(1, Contact::count());
    }

    public function test_phone_matching_is_used_when_email_does_not_match(): void
    {
        $owner = User::factory()->create();
        $existing = Contact::factory()->create([
            'user_id' => $owner->id,
            'email' => null,
            'normalized_email' => null,
            'phone' => '+255700000001',
            'normalized_phone' => '255700000001',
        ]);

        $resolution = $this->resolver()->resolve($owner->id, 'Ada', 'new@example.test', '+255 700-000-001');

        $this->assertSame(ContactResolution::RESOLVED, $resolution->status);
        $this->assertSame($existing->id, $resolution->contactId());
        $this->assertSame(1, Contact::count());
    }

    public function test_name_only_never_matches(): void
    {
        $owner = User::factory()->create();
        Contact::factory()->create([
            'user_id' => $owner->id,
            'name' => 'Ada Lovelace',
            'email' => null,
            'normalized_email' => null,
            'phone' => null,
            'normalized_phone' => null,
        ]);

        $resolution = $this->resolver()->resolve($owner->id, 'Ada Lovelace', null, null);

        $this->assertSame(ContactResolution::CREATED, $resolution->status);
        $this->assertSame(2, Contact::count());
    }

    public function test_guests_without_a_usable_identifier_get_separate_contacts(): void
    {
        $owner = User::factory()->create();

        $first = $this->resolver()->resolve($owner->id, 'Same Name', null, '12');
        $second = $this->resolver()->resolve($owner->id, 'Same Name', null, '12');

        $this->assertSame(ContactResolution::CREATED, $first->status);
        $this->assertSame(ContactResolution::CREATED, $second->status);
        $this->assertNotSame($first->contactId(), $second->contactId());
        $this->assertSame(2, Contact::count());
    }

    public function test_matching_never_crosses_accounts(): void
    {
        $ownerA = User::factory()->create();
        $ownerB = User::factory()->create();
        $foreign = Contact::factory()->create([
            'user_id' => $ownerA->id,
            'email' => 'shared@example.test',
            'normalized_email' => 'shared@example.test',
            'phone' => null,
            'normalized_phone' => null,
        ]);

        $resolution = $this->resolver()->resolve($ownerB->id, 'Other', 'shared@example.test', null);

        $this->assertSame(ContactResolution::CREATED, $resolution->status);
        $this->assertNotSame($foreign->id, $resolution->contactId());
        $this->assertSame($ownerB->id, $resolution->contact->user_id);
        $this->assertSame(2, Contact::count());
    }

    public function test_email_and_phone_disagreement_reports_a_conflict(): void
    {
        $owner = User::factory()->create();
        Contact::factory()->create([
            'user_id' => $owner->id,
            'email' => 'a@example.test',
            'normalized_email' => 'a@example.test',
            'phone' => null,
            'normalized_phone' => null,
        ]);
        Contact::factory()->create([
            'user_id' => $owner->id,
            'email' => null,
            'normalized_email' => null,
            'phone' => '+255700000001',
            'normalized_phone' => '255700000001',
        ]);

        $resolution = $this->resolver()->resolve($owner->id, 'Conflict', 'a@example.test', '+255700000001');

        $this->assertTrue($resolution->isConflict());
        $this->assertNull($resolution->contactId());
        $this->assertSame(2, Contact::count());
    }

    public function test_finders_are_scoped_by_account(): void
    {
        $ownerA = User::factory()->create();
        $ownerB = User::factory()->create();
        Contact::factory()->create([
            'user_id' => $ownerA->id,
            'email' => 'a@example.test',
            'normalized_email' => 'a@example.test',
            'phone' => null,
            'normalized_phone' => null,
        ]);

        $resolver = $this->resolver();

        $this->assertNotNull($resolver->findContactByEmail($ownerA->id, 'a@example.test'));
        $this->assertNull($resolver->findContactByEmail($ownerB->id, 'a@example.test'));
        $this->assertNull($resolver->findContactByEmail($ownerA->id, '   '));
    }
}
