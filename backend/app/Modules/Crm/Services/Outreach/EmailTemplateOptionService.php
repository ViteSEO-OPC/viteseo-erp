<?php

namespace App\Modules\Crm\Services\Outreach;

use App\Modules\Crm\Models\EmailTemplate;
use App\Modules\Crm\Models\EmailTemplateOption;
use App\Modules\Crm\Support\EmailTemplateCatalog;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Settings → Email Templates → custom purposes and tones: each organization's own additions to the template catalog.
 *
 * ── Strings, as before ──
 * A template stores its purpose and tone as plain strings. A custom value is stored on the template exactly like a
 * built-in one; its row here is what lets the organization choose it, reuse it and — while no template uses it —
 * delete it. Nothing that reads templates by purpose or tone changes.
 *
 * ── Never an automation key, however spelled ──
 * `crm_email_templates` is case-insensitive, and the automation resolver finds templates with a SQL
 * `where('purpose', $key)`. A custom purpose "Proposal" would therefore be found for the automation key `proposal`.
 * So a custom value is refused when it equals — ignoring case, and treating spaces, underscores and hyphens alike —
 * any built-in purpose, any automation purpose key, or a reserved key. The composer's exact, case-sensitive purpose
 * allowlist can then include custom purposes and still never reach an automation template, and the resolver itself
 * is left exactly as it is.
 *
 * ── Values ──
 * Trimmed, internal whitespace collapsed to single spaces, 2–60 characters, with at least one letter or number, and
 * no control or invisible formatting characters, braces, angle brackets, backslashes or backticks. One value per
 * organization and type, however spelled; re-adding a deleted value restores it. No rename in v1.
 */
class EmailTemplateOptionService
{
    public const TYPE_PURPOSE = 'purpose';

    public const TYPE_TONE = 'tone';

    public const TYPES = [self::TYPE_PURPOSE, self::TYPE_TONE];

    public const MIN_LENGTH = 2;

    public const MAX_LENGTH = 60;

    /**
     * Automation purpose keys reserved beyond AutomationSendEmailRequist::PURPOSES — named by the product owner as
     * automation purposes, so no custom purpose can take them before an automation does.
     */
    public const RESERVED_PURPOSE_KEYS = ['follow_up', 'closing'];

    public const EMPTY = 'Enter a name.';

    public const LENGTH = 'Use between 2 and 60 characters.';

    public const INVALID = 'Use letters, numbers, spaces and simple punctuation, with no braces, angle brackets, backslashes, backticks or control characters.';

    public const BUILT_IN = '“%s” is already a built-in %s. Choose it from the list instead.';

    public const RESERVED_AUTOMATION = '“%s” is reserved for the automation purpose “%s”, so it cannot be a custom purpose.';

    public const DUPLICATE = 'Your organization already has the custom %s “%s”.';

    public const IN_USE = 'The custom %s “%s” is used by %d %s. Give %s a different %s before deleting it.';

    /** Trimmed, with every run of whitespace a single space: the value as it is stored and shown. */
    public static function clean(string $value): string
    {
        return trim((string) preg_replace('/[\p{Z}\s]+/u', ' ', $value));
    }

    /** The value compared for duplicates and collisions: lower case, spaces, underscores and hyphens alike. */
    public static function normalize(string $value): string
    {
        return mb_strtolower(trim((string) preg_replace('/[\p{Z}\s_\-]+/u', ' ', $value)));
    }

    /** @return Collection<int, EmailTemplateOption> */
    public function custom(string $type): Collection
    {
        return EmailTemplateOption::query()->where('type', $type)->orderBy('value')->get();
    }

    /** @return list<string> */
    public function customValues(string $type): array
    {
        return $this->custom($type)->pluck('value')->all();
    }

    /** Every purpose a template in the current organization may have: the built-in ones and its custom ones. @return list<string> */
    public function purposes(): array
    {
        return [...EmailTemplateCatalog::purposes(), ...$this->customValues(self::TYPE_PURPOSE)];
    }

    /** @return list<string> */
    public function tones(): array
    {
        return [...EmailTemplateCatalog::TONES, ...$this->customValues(self::TYPE_TONE)];
    }

    /**
     * The catalog as Settings → Email Templates consumes it: the built-in vocabulary with the organization's custom
     * purposes (always composer purposes) and tones added, each marked custom. `tones` stays a list of strings.
     *
     * @return array<string, mixed>
     */
    public function options(): array
    {
        $catalog = EmailTemplateCatalog::options();
        $purposes = $this->custom(self::TYPE_PURPOSE);
        $tones = $this->custom(self::TYPE_TONE);

        return [
            ...$catalog,
            'purposes' => [
                ...array_map(fn (array $purpose): array => [...$purpose, 'custom' => false, 'optionId' => null], $catalog['purposes']),
                ...$purposes->map(fn (EmailTemplateOption $option): array => [
                    'value' => $option->value,
                    'kind' => EmailTemplateCatalog::KIND_COMPOSER,
                    'custom' => true,
                    'optionId' => (string) $option->id,
                ])->all(),
            ],
            'tones' => [...$catalog['tones'], ...$tones->pluck('value')->all()],
            'customTones' => $tones->map(fn (EmailTemplateOption $option): array => ['value' => $option->value, 'optionId' => (string) $option->id])->all(),
        ];
    }

    /**
     * The merged purposes and tones, each custom value with how many templates use it.
     *
     * @return array{purposes: list<array<string, mixed>>, tones: list<array<string, mixed>>}
     */
    public function catalogWithUsage(): array
    {
        $options = $this->options();
        $templates = EmailTemplate::query()->get(['purpose', 'tone']);
        $uses = fn (string $column, string $value): int => $templates->filter(fn (EmailTemplate $template): bool => $template->{$column} === $value)->count();

        return [
            'purposes' => array_map(
                fn (array $purpose): array => [...$purpose, 'templatesCount' => $purpose['custom'] ? $uses('purpose', $purpose['value']) : null],
                $options['purposes'],
            ),
            'tones' => [
                ...array_map(fn (string $tone): array => ['value' => $tone, 'custom' => false, 'optionId' => null, 'templatesCount' => null], EmailTemplateCatalog::TONES),
                ...array_map(fn (array $tone): array => [...$tone, 'custom' => true, 'templatesCount' => $uses('tone', $tone['value'])], $options['customTones']),
            ],
        ];
    }

    /** @throws ValidationException */
    public function create(string $type, string $raw, ?int $actorId): EmailTemplateOption
    {
        [$value, $normalized] = $this->validated($type, $raw);

        return DB::transaction(function () use ($type, $value, $normalized, $actorId): EmailTemplateOption {
            $existing = EmailTemplateOption::withTrashed()
                ->where('type', $type)
                ->where('normalized_value', $normalized)
                ->lockForUpdate()
                ->first();

            if ($existing !== null && ! $existing->trashed()) {
                throw $this->duplicate($type, $existing->value);
            }

            if ($existing !== null) {
                // The unique index covers deleted rows, so a value that was deleted is restored, spelled as given now.
                $existing->restore();
                $existing->forceFill(['value' => $value, 'created_by' => $actorId])->save();

                return $existing->fresh();
            }

            try {
                return EmailTemplateOption::create([
                    'type' => $type,
                    'value' => $value,
                    'normalized_value' => $normalized,
                    'created_by' => $actorId,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw $this->duplicate($type, $value);
            }
        });
    }

    /** @throws ValidationException while any template still uses the value */
    public function delete(EmailTemplateOption $option): void
    {
        $count = $this->usage($option);

        if ($count > 0) {
            throw ValidationException::withMessages([
                'option' => sprintf(self::IN_USE, $option->type, $option->value, $count, $count === 1 ? 'template' : 'templates', $count === 1 ? 'it' : 'them', $option->type),
            ]);
        }

        $option->delete();
    }

    /** Templates in the organization — active or not — whose purpose or tone is exactly this value. */
    public function usage(EmailTemplateOption $option): int
    {
        $column = $option->type === self::TYPE_TONE ? 'tone' : 'purpose';

        // Compared in PHP: the column is case-insensitive in MySQL, and only an exact match is this value.
        return EmailTemplate::query()
            ->where($column, $option->value)
            ->pluck($column)
            ->filter(fn (string $stored): bool => $stored === $option->value)
            ->count();
    }

    /**
     * @return array{0: string, 1: string} the value as stored, and its normalized form
     *
     * @throws ValidationException
     */
    private function validated(string $type, string $raw): array
    {
        $fail = fn (string $message): ValidationException => ValidationException::withMessages(['value' => $message]);

        if (trim($raw) === '') {
            throw $fail(self::EMPTY);
        }
        if (! mb_check_encoding($raw, 'UTF-8')
            || preg_match('/\p{C}/u', $raw) === 1
            || preg_match('/[{}<>\\\\`]/', $raw) === 1
            || preg_match('/[\p{L}\p{N}]/u', $raw) !== 1) {
            throw $fail(self::INVALID);
        }

        $value = self::clean($raw);
        $length = mb_strlen($value);
        if ($length < self::MIN_LENGTH || $length > self::MAX_LENGTH) {
            throw $fail(self::LENGTH);
        }

        $normalized = self::normalize($value);
        foreach ($this->builtIn($type) as [$builtIn, $automation]) {
            if (self::normalize($builtIn) === $normalized) {
                throw $fail($automation ? sprintf(self::RESERVED_AUTOMATION, $value, $builtIn) : sprintf(self::BUILT_IN, $builtIn, $type));
            }
        }

        return [$value, $normalized];
    }

    /** @return list<array{0: string, 1: bool}> each built-in value, and whether it is an automation key */
    private function builtIn(string $type): array
    {
        if ($type === self::TYPE_TONE) {
            return array_map(fn (string $tone): array => [$tone, false], EmailTemplateCatalog::TONES);
        }

        return [
            ...array_map(fn (string $purpose): array => [$purpose, false], EmailTemplateCatalog::COMPOSER_PURPOSES),
            ...array_map(fn (string $key): array => [$key, true], [...EmailTemplateCatalog::automationPurposes(), ...self::RESERVED_PURPOSE_KEYS]),
        ];
    }

    private function duplicate(string $type, string $value): ValidationException
    {
        return ValidationException::withMessages(['value' => sprintf(self::DUPLICATE, $type, $value)]);
    }
}
