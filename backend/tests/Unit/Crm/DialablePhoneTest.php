<?php

declare(strict_types=1);

namespace Tests\Unit\Crm;

use App\Modules\Crm\Support\DialablePhone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The gate between `crm_leads.phone` — free text with no format rule, ever — and a real carrier.
 *
 * The refusals matter as much as the conversions. This column genuinely contains "via FB page" and
 * "N/A" in production data, and the cost of guessing is not a validation error: it is a charged call
 * that rings a stranger and writes a record against the lead.
 */
class DialablePhoneTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function dialableNumbers(): array
    {
        return [
            'PH local mobile with spaces' => ['0917 847 5233', '+639178475233'],
            'PH local mobile unspaced' => ['09178475233', '+639178475233'],
            'PH local mobile with dashes' => ['0917-847-5233', '+639178475233'],
            'already E.164' => ['+639178475233', '+639178475233'],
            'E.164 with spaces' => ['+63 917 847 5233', '+639178475233'],
            'country code without plus' => ['639178475233', '+639178475233'],
            'international non-PH is passed through' => ['+14425933400', '+14425933400'],
            'parenthesised E.164' => ['+1 (442) 593-3400', '+14425933400'],
            'surrounding whitespace' => ['  09178475233  ', '+639178475233'],
        ];
    }

    #[DataProvider('dialableNumbers')]
    public function test_a_usable_number_is_converted_to_e164(string $raw, string $expected): void
    {
        $phone = DialablePhone::parse($raw);

        $this->assertTrue($phone->isDialable(), "expected dialable: {$raw}");
        $this->assertSame($expected, $phone->e164);
        $this->assertNull($phone->reason);
    }

    /** @return array<string, array{?string}> */
    public static function refusedNumbers(): array
    {
        return [
            'null' => [null],
            'empty' => [''],
            'whitespace only' => ['   '],
            'prose' => ['via FB page'],
            'not applicable' => ['N/A'],
            'none' => ['none'],
            'two numbers slash' => ['0917 111 2222 / 0918 333 4444'],
            'two numbers comma' => ['09171112222, 09183334444'],
            'two numbers or' => ['09171112222 or 09183334444'],
            'extension' => ['+6321234567 ext 22'],
            'no country code' => ['5551234'],
            'bare landline digits' => ['1234567'],
            'PH landline with leading zero' => ['02 8123 4567'],
            'too short international' => ['+123'],
            'too long international' => ['+1234567890123456'],
            'digits only garbage' => ['12312312312312'],
        ];
    }

    #[DataProvider('refusedNumbers')]
    public function test_an_unusable_number_is_refused_with_a_reason(?string $raw): void
    {
        $phone = DialablePhone::parse($raw);

        $this->assertFalse($phone->isDialable(), 'expected refusal: '.var_export($raw, true));
        $this->assertNull($phone->e164);
        $this->assertNotNull($phone->reason);
        $this->assertNotSame('', $phone->reason);
    }

    public function test_a_refusal_reason_is_written_for_the_bd(): void
    {
        // The reason is rendered verbatim in the Call panel, so it has to say what to DO, not name an
        // internal rule. This pins the intent rather than the exact wording.
        $this->assertStringContainsString('no phone number', DialablePhone::parse(null)->reason ?? '');
        $this->assertStringContainsString('more than one', DialablePhone::parse('1/2')->reason ?? '');
    }

    public function test_parsing_never_mutates_the_input_form(): void
    {
        // The class must be a pure reader: `crm_leads.phone` keeps its original value forever, and
        // nothing here may be mistaken for a normalizer that writes back.
        $raw = '0917 847 5233';
        DialablePhone::parse($raw);

        $this->assertSame('0917 847 5233', $raw);
    }
}
