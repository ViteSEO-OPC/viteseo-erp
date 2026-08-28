<?php

namespace App\Modules\Crm\Requests;

use App\Modules\Crm\Enums\NerbyProvider;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for choosing which provider Nearby uses.
 *
 * Its own request, separate from provider configuration, because selecting an engine and authorising
 * it to spend money are different decisions. Conflating them would mean one mis-click could both
 * switch provider and start billing — so selection never enables anything and never touches a
 * credential.
 *
 * The allowed set is read from {@see NerbyProvider} rather than listed here, so a provider added later
 * becomes selectable without a validation change (and, equally, cannot be selected before it exists).
 */
class SelectNerbyProviderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Route-level `role:super_admin|crm_admin` authorizes.
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'provider' => ['required', Rule::in(array_column(NerbyProvider::cases(), 'value'))],
        ];
    }

    public function provider(): NerbyProvider
    {
        return NerbyProvider::from((string) $this->validated('provider'));
    }
}
