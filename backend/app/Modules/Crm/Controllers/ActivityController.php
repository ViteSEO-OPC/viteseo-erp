<?php

namespace App\Modules\Crm\Controllers;

use App\Http\Controllers\Controller;

use App\Modules\Crm\Resources\ActivityResource;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\Lead;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ActivityController extends Controller
{
    /**
     * Generic, reusable activity feed (newest first).
     *
     * Reads the append-only Activity log that model observers already write — this
     * endpoint performs NO logging and NO business logic, it only exposes existing
     * records. Intentionally consumer-agnostic so it can power the Activity Logs page,
     * notifications, admin audit history, and recent-activity widgets alike. Soft-deleted
     * subjects are resolved via withTrashed so "deleted" rows keep their label.
     *
     * Ownership-scoped via Activity::scopeVisibleTo — the single source of truth for
     * activity visibility. Admin/SA see the full CRM feed; a BD sees only their own
     * activities and activities on the leads they own. Every consumer (Dashboard widget,
     * Activity Logs page, future surfaces) reads this same scoped query.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $activities = Activity::query()
            ->visibleTo($request->user())
            ->forCrmReporting() // exclude unqualified-prospect activity (Nearby pool) from CRM reporting
            ->with([
                'causer',
                'subject' => fn ($morphTo) => $morphTo->constrain([
                    Lead::class => fn ($query) => $query->withTrashed(),
                ]),
            ])
            ->latest('id')
            ->paginate(15);

        return ActivityResource::collection($activities);
    }
}
