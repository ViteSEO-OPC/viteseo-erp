<?php

namespace Tests\Feature\Crm;

use App\Modules\Crm\Enums\NotificationType;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Guards the catalog invariant: a NotificationType is complete or it does not exist.
 *
 * The convention that every type must define a title, message and deep-link was
 * approved in 2026-07-16 and lived as a comment — so a type could ship with two of
 * the three and nobody would notice until a blank row appeared in someone's bell.
 * The presenter registry makes that structural and this test makes it enforced:
 * add a case without a presenter and `presenter()` throws \UnhandledMatchError here
 * first, in CI, rather than in production at render time.
 *
 * Deliberately walks `NotificationType::cases()` rather than a hand-written list —
 * a list would need updating alongside the enum, which is exactly the coupling the
 * registry removed.
 */
class NotificationCatalogTest extends TestCase
{
    /** @return array<string, array{NotificationType}> */
    public static function catalog(): array
    {
        return array_reduce(
            NotificationType::cases(),
            fn (array $carry, NotificationType $type): array => $carry + [$type->value => [$type]],
            []
        );
    }

    #[DataProvider('catalog')]
    public function test_every_catalog_type_resolves_a_presenter(NotificationType $type): void
    {
        // Throws \UnhandledMatchError if a case was added without a presenter.
        $this->assertNotNull($type->presenter());
    }

    /**
     * A title is the one field with no null path — a notification without one is a
     * blank row. Asserted against an EMPTY payload on purpose: historical rows were
     * written by older producers and may be missing keys the current one always sets.
     */
    #[DataProvider('catalog')]
    public function test_every_type_produces_a_title_even_from_an_empty_payload(NotificationType $type): void
    {
        $title = $type->presenter()->title([]);

        $this->assertNotSame('', trim($title), "{$type->value} produced an empty title.");
    }

    /**
     * Message and link may legitimately be null, but they must not blow up on a
     * sparse payload — that would take down the whole feed, not just one row.
     */
    #[DataProvider('catalog')]
    public function test_every_type_survives_a_sparse_payload(NotificationType $type): void
    {
        $presenter = $type->presenter();

        $this->assertNull($presenter->message([]), "{$type->value} invented a message from nothing.");
        $this->assertNull($presenter->link([]), "{$type->value} invented a link from nothing.");
    }

    /** Catalog values are persisted inside each row's `data`, so renaming one orphans history. */
    public function test_the_shipped_type_values_are_unchanged(): void
    {
        $values = array_map(fn (NotificationType $t): string => $t->value, NotificationType::cases());

        $this->assertContains('inbound_reply', $values);
        $this->assertContains('planner_reminder', $values);
    }
}
