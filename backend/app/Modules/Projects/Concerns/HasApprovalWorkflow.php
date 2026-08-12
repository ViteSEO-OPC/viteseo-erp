<?php

declare(strict_types=1);

namespace App\Modules\Projects\Concerns;

use App\Models\User;
use App\Modules\Projects\Enums\ApprovalStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Review state shared by Task and Subtask (see the approval-workflow migration).
 *
 * These are deliberately LOCAL scopes, not a global scope: a global scope would
 * also apply to UUID route-model binding, so the review queue could not resolve
 * the very rows it exists to act on. The trade-off is that every query site must
 * opt in — see the call sites of approved()/visibleTo() across the module.
 */
trait HasApprovalWorkflow
{
    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_id');
    }

    /**
     * Real work only — what belongs on a board, in a workload total, or in search.
     */
    public function scopeApproved(Builder $query): void
    {
        $query->where('approval_status', ApprovalStatus::APPROVED);
    }

    /**
     * What $userId may see: everything approved, plus their own proposals still
     * pending or rejected (so the author can track and revise them).
     */
    public function scopeVisibleTo(Builder $query, ?int $userId): void
    {
        $query->where(function (Builder $q) use ($userId): void {
            $q->where('approval_status', ApprovalStatus::APPROVED);

            if ($userId !== null) {
                $q->orWhere('submitted_by_id', $userId);
            }
        });
    }

    /**
     * Proposals sitting in the PM/Admin review queue.
     */
    public function scopeAwaitingReview(Builder $query): void
    {
        $query->where('approval_status', ApprovalStatus::PENDING);
    }

    /**
     * True while the row is a proposal that has not yet been approved.
     */
    public function isProposal(): bool
    {
        return $this->approval_status !== ApprovalStatus::APPROVED;
    }

    /**
     * Whether $userId authored this row as a proposal that is still unapproved
     * (and may therefore still edit it in full).
     */
    public function isEditableProposalFor(?int $userId): bool
    {
        return $userId !== null
            && $this->submitted_by_id !== null
            && (int) $this->submitted_by_id === $userId
            && $this->isProposal();
    }
}
