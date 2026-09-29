<?php

namespace App\Modules\Crm\Services\Discovery;

use App\Modules\Crm\Enums\NerbyProvider;

/**
 * What a nationwide run may cost on a given provider — ONE definition, read by both sides.
 *
 * THE FENCE AND THE CONTROL MUST NOT BE TWO OPINIONS. The orchestrator refuses a nationwide run
 * whose budget exceeds what the provider may spend; the dialog decides whether to offer the mode at
 * all and which budget to ask for. Those were separate facts in separate languages once, and the
 * result was a Google-only rule in the frontend that outlived the server rule it was mirroring: the
 * mode stayed hidden for months after the server had stopped refusing it. So this class answers the
 * question once and both callers read the answer.
 *
 * THE BUDGET IS THE PROVIDER'S, NOT THE MODE'S. Google Places is a paid endpoint metered in dollars
 * by {@see GooglePlacesSpendGate}, so a nationwide sweep there is bounded by money and keeps the
 * default budget it has always had. Every other provider answers from Overpass — free, shared and
 * community-funded — so its bound is the query count, and that is a much smaller number. Neither is
 * a statement about how much of the country exists; both are statements about what one run may spend.
 *
 * `available` AND `maxTasks` ANSWER DIFFERENT QUESTIONS. What a provider may SPEND is not the same
 * as whether a person may START a run from the page: the OpenStreetMap baseline is fenced exactly
 * like the AI lane and the orchestrator accepts the same bounded run on it, yet it is not offered as
 * a control because that pool fills on a schedule. Reading `maxTasks` as permission — or `available`
 * as a budget — is how the fence gets tightened by accident.
 */
final class NationwideCapability
{
    private function __construct(
        /** May the nationwide mode be offered and started on this provider? */
        public readonly bool $available,
        /** The budget one nationwide run may use here, in (cell x niche) searches. */
        public readonly int $maxTasks,
        /** Why that number, as a stable word a UI can branch on without parsing prose. */
        public readonly string $reason,
    ) {}

    public static function for(NerbyProvider $provider): self
    {
        if ($provider === NerbyProvider::GOOGLE_PLACES) {
            // UNCHANGED. The same default the API has always applied when no budget is sent, now
            // stated out loud so the caller asks for it rather than inheriting it silently.
            return new self(true, (int) config('discovery.nationwide.max_tasks', 400), 'default_budget');
        }

        if ($provider === NerbyProvider::OPENAI) {
            return new self(
                true,
                (int) config('discovery.nationwide.max_tasks_without_google', 60),
                'overpass_fence',
            );
        }

        // THE BASELINE IS FENCED THE SAME WAY, BUT IT IS NOT AN ON-DEMAND SURFACE.
        //
        // TWO DIFFERENT QUESTIONS, and collapsing them is a bug: `maxTasks` is what this provider
        // may SPEND, `available` is whether a person may START it from the page. OpenStreetMap
        // answers from Overpass exactly as the AI lane does, so it earns the same budget — the
        // orchestrator must keep accepting the bounded run it accepts today. What it does not get is
        // the control: that pool fills on a schedule and the board reads it, so putting a button on
        // it would change how the deployment behaves rather than un-hide something already built.
        return new self(
            false,
            (int) config('discovery.nationwide.max_tasks_without_google', 60),
            'baseline_fills_on_a_schedule',
        );
    }

    /** @return array{available: bool, maxTasks: int, reason: string} */
    public function toArray(): array
    {
        return [
            'available' => $this->available,
            'maxTasks' => $this->maxTasks,
            'reason' => $this->reason,
        ];
    }
}
