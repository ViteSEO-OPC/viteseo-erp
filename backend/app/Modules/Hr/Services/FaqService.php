<?php

namespace App\Modules\Hr\Services;

use App\Models\User;
use App\Modules\Hr\Models\Faq;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;

class FaqService
{
    public function __construct(private readonly AuditService $audit) {}

    public const CATEGORIES = ['payroll', 'leave', 'attendance', 'benefits', 'general'];

    public const STATUSES = ['draft', 'published', 'archived'];

    /**
     * HR sees every entry; employees see only published ones. Supports a text
     * search over question / answer / category / tags. Pinned first.
     */
    public function list(User $user, ?string $search = null): Collection
    {
        $query = Faq::with('editor')
            ->when(! $user->hasAnyRole(['hr_officer', 'super_admin']), fn ($q) => $q->where('status', 'published'))
            ->when($search, function ($q) use ($search) {
                $like = '%' . $search . '%';
                $q->where(function ($w) use ($like) {
                    $w->where('question', 'like', $like)
                        ->orWhere('answer', 'like', $like)
                        ->orWhere('category', 'like', $like)
                        ->orWhere('tags', 'like', $like);
                });
            })
            ->orderByDesc('is_pinned')
            ->orderByDesc('updated_at');

        return $query->get();
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): Faq
    {
        // fields() supplies 'status' only when the caller chose one; otherwise a
        // new entry starts as a draft.
        $faq = Faq::create($this->fields($data) + [
            'status' => 'draft',
            'updated_by' => Auth::id(),
        ]);

        $this->audit->log('created', 'FAQ', "Created FAQ '{$faq->question}'", $faq);

        return $faq;
    }

    /** @param array<string, mixed> $data */
    public function update(Faq $faq, array $data): Faq
    {
        $faq->update($this->fields($data) + ['updated_by' => Auth::id()]);

        $this->audit->log('updated', 'FAQ', "Updated FAQ '{$faq->question}'", $faq);

        return $faq->load('editor');
    }

    public function setStatus(Faq $faq, string $status): Faq
    {
        $faq->update(['status' => $status, 'updated_by' => Auth::id()]);

        $verb = match ($status) {
            'published' => 'Published',
            'archived' => 'Archived',
            default => 'Updated status of',
        };
        $this->audit->log('updated', 'FAQ', "{$verb} FAQ '{$faq->question}'", $faq);

        return $faq->load('editor');
    }

    public function togglePin(Faq $faq): Faq
    {
        $faq->update(['is_pinned' => ! $faq->is_pinned, 'updated_by' => Auth::id()]);

        $verb = $faq->is_pinned ? 'Pinned' : 'Unpinned';
        $this->audit->log('updated', 'FAQ', "{$verb} FAQ '{$faq->question}'", $faq);

        return $faq->load('editor');
    }

    public function delete(Faq $faq): void
    {
        $faq->delete();

        $this->audit->log('removed', 'FAQ', "Removed FAQ '{$faq->question}'", $faq);
    }

    /** Bump the view count (insights) — published entries only. */
    public function registerView(Faq $faq): void
    {
        if ($faq->status === 'published') {
            $faq->increment('view_count');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function fields(array $data): array
    {
        $fields = [
            'question' => $data['question'],
            'answer' => $data['answer'],
            'category' => $data['category'],
            'tags' => $data['tags'] ?? [],
        ];

        // Pinning and status have their own endpoints (togglePin / setStatus), so
        // only overwrite them when the caller actually sent a value — otherwise a
        // plain edit would unpin the entry or reset its status.
        foreach (['is_pinned', 'status'] as $key) {
            if (isset($data[$key])) {
                $fields[$key] = $data[$key];
            }
        }

        return $fields;
    }
}
