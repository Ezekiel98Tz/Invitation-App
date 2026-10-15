<?php

namespace Tests\Unit;

use App\Support\ContactNormalizer;
use Tests\TestCase;

class ContactNormalizerTest extends TestCase
{
    public function test_email_is_trimmed_and_lowercased(): void
    {
        $this->assertSame('ada@example.test', ContactNormalizer::email('  ADA@Example.TEST '));
    }

    public function test_blank_email_is_unavailable(): void
    {
        $this->assertNull(ContactNormalizer::email('   '));
        $this->assertNull(ContactNormalizer::email(''));
        $this->assertNull(ContactNormalizer::email(null));
    }

    public function test_phone_keeps_digits_only(): void
    {
        $this->assertSame('255712345678', ContactNormalizer::phone('+255 712-345-678'));
        $this->assertSame('0712345678', ContactNormalizer::phone('(0712) 345 678'));
    }

    public function test_phone_preserves_leading_zeros(): void
    {
        $this->assertSame('0712345678', ContactNormalizer::phone('0712345678'));
    }

    public function test_short_or_empty_phone_is_unusable(): void
    {
        $this->assertNull(ContactNormalizer::phone('123456'));
        $this->assertNull(ContactNormalizer::phone('abc'));
        $this->assertNull(ContactNormalizer::phone(''));
        $this->assertNull(ContactNormalizer::phone(null));
    }

    public function test_national_and_international_formats_are_not_equated(): void
    {
        $this->assertNotSame(
            ContactNormalizer::phone('0712345678'),
            ContactNormalizer::phone('+255712345678')
        );
    }
}
