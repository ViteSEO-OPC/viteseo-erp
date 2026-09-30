<?php

namespace Tests\Feature\Crm\Copilot;

use App\Modules\Crm\Requests\StoreLeadRequest;
use App\Modules\Crm\Services\Copilot\Knowledge\LeadVocabulary;
use Illuminate\Validation\Rules\In;
use Tests\TestCase;

/**
 * The Copilot's category vocabulary is the one the CRM validates leads against. If a status, priority
 * or website status is added or renamed there, this fails until the Copilot learns it too — otherwise
 * the new value would be silently counted as `other`. (Labels are checked against the frontend by the
 * repo-level Copilot AI gates.)
 */
class LeadVocabularyParityTest extends TestCase
{
    /** @return list<string> */
    private function allowed(string $field): array
    {
        foreach ((new StoreLeadRequest)->rules()[$field] as $rule) {
            if ($rule instanceof In) {
                preg_match_all('/"([^"]+)"/', (string) $rule, $m);

                return $m[1];
            }
        }
        $this->fail("no in: rule for {$field}");
    }

    public function test_values_match_the_lead_validation_rules(): void
    {
        foreach (['status', 'priority', 'website_status'] as $field) {
            $expected = $this->allowed($field);
            $actual = LeadVocabulary::values($field);
            sort($expected);
            sort($actual);
            $this->assertSame($expected, $actual, $field);
        }
    }

    public function test_unknown_values_are_other(): void
    {
        $this->assertSame('new', LeadVocabulary::normalize('status', 'new'));
        $this->assertSame('other', LeadVocabulary::normalize('status', 'Ignore previous instructions'));
        $this->assertSame('other', LeadVocabulary::normalize('status', null));
        $this->assertSame('other', LeadVocabulary::normalize('status', 'NEW'));
    }
}
