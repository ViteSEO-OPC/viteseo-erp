<?php

namespace App\Modules\Crm\Services\Outreach;

use App\Models\Org;
use App\Modules\Crm\Models\EmailDesign;
use App\Modules\Crm\Models\EmailTemplate;
use App\Support\CurrentOrg;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Settings → Email Designs: the reusable looks designed email templates are shown in.
 *
 * ── The default design ──
 * Every organization has exactly one, created the first time anything needs it and never overwritten after
 * that: an admin's edits to it stay. New designed templates use it unless another design is chosen. It
 * cannot be deleted. The first-use race is closed by locking the organization's row while it is created.
 *
 * ── Reuse ──
 * Templates point at a design; nothing of the design is copied into them. When a design's layout changes,
 * every template that uses it renders with the change. The one thing stored per template — its plain-text
 * `body`, for the readers of `body` that do not understand designs — is regenerated from the new layout in
 * the same transaction, and a change that would make any of those templates too large for Gmail or for the
 * `body` column is refused before anything is written.
 *
 * ── Apply to all ──
 * Creating or saving a design can also point EVERY designed template in the organization at it, in the same
 * transaction. The admin confirms a count first, and the change is refused if the number of designed templates
 * is no longer that count, or if the design would break any of them. Plain-text templates are never touched,
 * and nothing of the design is copied into a template — only its id.
 *
 * ── Deleting ──
 * A design is soft-deleted, and only when it is not the default and no template uses it, so a template
 * never loses its look.
 */
class EmailDesignService
{
    public const NAME_TAKEN = 'Another design already has this name.';

    public const DEFAULT_CANNOT_BE_DELETED = 'The default design cannot be deleted: designed templates use it when no other design is chosen.';

    public const IN_USE = 'This design is used by %d %s. Choose another design for %s before deleting it.';

    public const BREAKS_TEMPLATE = 'This change would break the template “%s” (%s · %s): %s';

    public const APPLY_COUNT_CHANGED = 'You confirmed %d designed templates, but there are now %d. Review the change and confirm again.';

    public function __construct(private readonly EmailTemplateDesignService $rendering) {}

    /** Every design in the current organization, the default first — creating the default if there is none. */
    public function all(): Collection
    {
        $this->defaultDesign();

        return EmailDesign::query()->withCount('templates')->orderByDesc('is_default')->orderBy('name')->get();
    }

    /** The current organization's default design, created with the default layout the first time it is needed. */
    public function defaultDesign(): EmailDesign
    {
        $existing = EmailDesign::query()->where('is_default', true)->orderBy('id')->first();
        if ($existing !== null) {
            return $existing;
        }

        $orgId = CurrentOrg::id();
        if ($orgId === null) {
            throw new RuntimeException('An email design needs a current organization.');
        }

        return DB::transaction(function () use ($orgId): EmailDesign {
            // Serializes first use per organization, so two requests cannot both create a default.
            $org = Org::query()->lockForUpdate()->find($orgId);

            return EmailDesign::query()->where('is_default', true)->orderBy('id')->first()
                ?? EmailDesign::create([
                    'name' => self::defaultName($org?->name),
                    'is_default' => true,
                    'layout' => self::defaultLayout(),
                    'created_by' => null,
                ]);
        });
    }

    /**
     * A new organization's default look: its logo in the header, a signature area, and an empty footer.
     * Every style is left to the organization's branding.
     *
     * @return array<string, mixed>
     */
    public static function defaultLayout(): array
    {
        return [
            'version' => 1,
            'styles' => [],
            'header' => [['type' => 'logo', 'id' => 'header-logo', 'width' => 160, 'align' => 'center']],
            'signature' => ['enabled' => true, 'sign_off' => 'Best regards,', 'align' => 'left'],
            'footer' => [],
        ];
    }

    public static function defaultName(?string $organizationName): string
    {
        $name = trim((string) $organizationName);

        return mb_substr(($name === '' ? 'Organization' : $name).' Default', 0, 100);
    }

    /** @param  array{name: string, layout: string, apply_to_all?: bool, apply_to_all_count?: int}  $data */
    public function create(array $data, ?int $actorId): EmailDesign
    {
        $this->assertNameIsFree($data['name']);
        $layout = $this->rendering->decode($data['layout']);

        return DB::transaction(function () use ($data, $layout, $actorId): EmailDesign {
            $templates = $this->appliesToAll($data) ? $this->designedTemplatesToApply((int) $data['apply_to_all_count']) : new Collection;
            $this->assertNothingBreaks($templates, $layout, 'apply_to_all');

            $design = EmailDesign::create([
                'name' => $data['name'],
                'is_default' => false,
                'layout' => $layout,
                'created_by' => $actorId,
            ]);
            $this->pointAt($design, $layout, $templates);

            return $design->loadCount('templates');
        });
    }

    /** @param  array{name?: string, layout?: string, apply_to_all?: bool, apply_to_all_count?: int}  $data */
    public function update(EmailDesign $design, array $data): EmailDesign
    {
        if (array_key_exists('name', $data) && $data['name'] !== $design->name) {
            $this->assertNameIsFree($data['name'], $design->id);
        }

        $attributes = array_intersect_key($data, array_flip(['name']));
        $layout = $design->layout;
        if (array_key_exists('layout', $data)) {
            $layout = $this->rendering->decode($data['layout']);
            $attributes['layout'] = $layout;
        }

        DB::transaction(function () use ($design, $data, $attributes, $layout): void {
            // Applying to all reaches every designed template, which includes the ones already using the design;
            // otherwise a new layout reaches just those.
            $templates = match (true) {
                $this->appliesToAll($data) => $this->designedTemplatesToApply((int) $data['apply_to_all_count']),
                array_key_exists('layout', $attributes) => $design->templates()->whereNotNull('content')->get(),
                default => new Collection,
            };
            $this->assertNothingBreaks($templates, $layout, array_key_exists('layout', $attributes) ? 'layout' : 'apply_to_all');

            $design->update($attributes);
            $this->pointAt($design, $layout, $templates);
        });

        return $design->fresh()->loadCount('templates');
    }

    /**
     * What "Apply to all templates" would change, for the confirmation: every DESIGNED template in the current
     * organization — active or not — would use the design; those already using it; those switching from
     * another design; and the plain-text templates it leaves exactly as they are.
     *
     * @return array{designedTemplates: int, alreadyUsing: int, switching: int, plainTextTemplates: int}
     */
    public function applyToAllSummary(?EmailDesign $design): array
    {
        $designed = EmailTemplate::query()->whereNotNull('content')->count();
        $alreadyUsing = $design === null ? 0 : EmailTemplate::query()->whereNotNull('content')->where('email_design_id', $design->id)->count();

        return [
            'designedTemplates' => $designed,
            'alreadyUsing' => $alreadyUsing,
            'switching' => $designed - $alreadyUsing,
            'plainTextTemplates' => EmailTemplate::query()->whereNull('content')->count(),
        ];
    }

    public function delete(EmailDesign $design): void
    {
        if ($design->is_default) {
            throw ValidationException::withMessages(['design' => self::DEFAULT_CANNOT_BE_DELETED]);
        }

        $count = $design->templates()->count();
        if ($count > 0) {
            throw ValidationException::withMessages([
                'design' => sprintf(self::IN_USE, $count, $count === 1 ? 'template' : 'templates', $count === 1 ? 'it' : 'them'),
            ]);
        }

        $design->delete();
    }

    /** @param  array<string, mixed>  $data */
    private function appliesToAll(array $data): bool
    {
        return filter_var($data['apply_to_all'] ?? false, FILTER_VALIDATE_BOOL);
    }

    /**
     * Every designed template in the current organization, locked so the set cannot change between checking
     * it and pointing it at the design — refused when it is no longer the number the admin confirmed.
     * Plain-text templates are never in it: applying a design does not convert anything.
     */
    private function designedTemplatesToApply(int $confirmed): Collection
    {
        $templates = EmailTemplate::query()->whereNotNull('content')->orderBy('id')->lockForUpdate()->get();

        if ($templates->count() !== $confirmed) {
            throw ValidationException::withMessages([
                'apply_to_all_count' => sprintf(self::APPLY_COUNT_CHANGED, $confirmed, $templates->count()),
            ]);
        }

        return $templates;
    }

    /** @param  array<string, mixed>  $layout */
    private function assertNothingBreaks(Collection $templates, array $layout, string $key): void
    {
        foreach ($templates as $template) {
            $problem = $this->rendering->composedProblem($layout, $template->content);
            if ($problem !== null) {
                throw ValidationException::withMessages([
                    $key => sprintf(self::BREAKS_TEMPLATE, $template->subject, $template->purpose, $template->tone, $problem),
                ]);
            }
        }
    }

    /**
     * Points templates at the design and regenerates their bodies from its layout. Only the design id is written
     * to a template — never any of the layout. A template whose design changed counts as edited; one that
     * already used the design only has its body follow the layout, so its updated_at stays.
     *
     * @param  array<string, mixed>  $layout
     */
    private function pointAt(EmailDesign $design, array $layout, Collection $templates): void
    {
        foreach ($templates as $template) {
            $changes = ['body' => $this->rendering->plainTextBody($layout, $template->content)];
            if ((int) $template->email_design_id !== $design->id) {
                $changes['email_design_id'] = $design->id;
                $changes['updated_at'] = now();
            }

            EmailTemplate::query()->whereKey($template->id)->toBase()->update($changes);
        }
    }

    private function assertNameIsFree(string $name, ?int $ignoreId = null): void
    {
        $taken = EmailDesign::query()
            ->where('name', $name)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages(['name' => self::NAME_TAKEN]);
        }
    }
}
