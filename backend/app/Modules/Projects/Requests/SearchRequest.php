<?php

declare(strict_types=1);

namespace App\Modules\Projects\Requests;

use App\Modules\Projects\Services\SearchService;
use Illuminate\Foundation\Http\FormRequest;

class SearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Trim before validating so a padded "  a  " fails the min-length rule
     * rather than reaching the service as a 1-character term.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('q') && is_string($this->input('q'))) {
            $this->merge(['q' => trim($this->input('q'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'q' => ['required', 'string', 'min:'.SearchService::MIN_TERM_LENGTH, 'max:100'],
            // Per-entity cap. Kept small — this feeds a jump-to menu, not a page.
            'limit' => ['nullable', 'integer', 'min:1', 'max:20'],
        ];
    }

    public function term(): string
    {
        return trim((string) $this->validated('q'));
    }

    public function limit(): int
    {
        return (int) ($this->validated('limit') ?? 5);
    }
}
