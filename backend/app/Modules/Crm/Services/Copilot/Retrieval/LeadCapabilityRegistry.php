<?php

namespace App\Modules\Crm\Services\Copilot\Retrieval;

use InvalidArgumentException;

/**
 * THE CLOSED SET of capabilities about one authorised lead — kept apart from
 * {@see CapabilityRegistry} so that Phase 1's aggregates-only capabilities stay provably free of
 * per-lead data. Listed here and nowhere else; they run only when the request carried a lead that
 * {@see AuthorizedLead::resolve()} accepted. Phase 2 Slice 1 has one: the lead's business name.
 */
class LeadCapabilityRegistry
{
    /** @var array<string, LeadScopedCapability> */
    private readonly array $capabilities;

    public function __construct(LeadSubjectRetriever $subject)
    {
        $all = [];
        foreach ([$subject] as $capability) {
            $all[$capability->key()] = $capability;
        }
        $this->capabilities = $all;
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->capabilities);
    }

    public function get(string $key): LeadScopedCapability
    {
        return $this->capabilities[$key] ?? throw new InvalidArgumentException("Unknown lead-scoped Copilot capability: {$key}");
    }
}
