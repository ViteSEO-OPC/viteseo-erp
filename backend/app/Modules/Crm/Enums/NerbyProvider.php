<?php

namespace App\Modules\Crm\Enums;

/**
 * The discovery engines Nearby can be configured to use.
 *
 * OPENSTREETMAP IS THE DEFAULT AND THE PERMANENT BASELINE. It needs no API key, no vendor and no
 * billing, and every other provider is measured against it rather than assumed to beat it. When it is
 * selected — or when nothing is configured — Nearby behaves exactly as it always has.
 *
 * ADDING A PROVIDER IS A CASE HERE AND NOTHING ELSE. The settings table is keyed by provider with a
 * generic credential column and a JSON options bag, so a further engine needs no migration, no new
 * columns, and no change to the resolver. That is deliberate: adding the case is what makes a
 * provider selectable, which is how Google Places came in — subject, as any structured Places
 * provider is, to caching/redisplay licensing, since the CRM persists discovered records as leads
 * indefinitely.
 *
 * Whatever gets added, the contract is the same for all of them: a provider may change WHICH
 * candidates are discovered. It may never touch how a qualified lead is scored.
 */
enum NerbyProvider: string
{
    case OPENSTREETMAP = 'openstreetmap';
    case OPENAI = 'openai';
    case GOOGLE_PLACES = 'google_places';

    public static function default(): self
    {
        return self::OPENSTREETMAP;
    }

    /**
     * The provider a stored/submitted string names, or the safe default.
     *
     * Degrades rather than throws: a value written by a newer release, a hand-edited row, or a
     * provider that has since been withdrawn must not break discovery or 500 the settings page.
     * Falling back to OpenStreetMap is always safe, and the resolver reports the fallback rather than
     * concealing it.
     */
    public static function fromStringOrDefault(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::default();
    }

    /**
     * Whether this provider needs credentials before it can run.
     *
     * Drives the resolver's fallback rules generically, so a credentialed provider added later
     * inherits "selected but unconfigured ⇒ visibly falls back to OSM" without touching the resolver.
     */
    public function requiresCredentials(): bool
    {
        return $this !== self::OPENSTREETMAP;
    }

    /** Keys this provider accepts inside its `options` bag. Empty means it takes no options. */
    public function optionKeys(): array
    {
        return match ($this) {
            self::OPENAI => ['model'],
            self::GOOGLE_PLACES => [],
            self::OPENSTREETMAP => [],
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::OPENSTREETMAP => 'Python / OpenStreetMap',
            self::OPENAI => 'OpenAI (AI-Powered)',
            self::GOOGLE_PLACES => 'Google Places (API New)',
        };
    }
}
