<?php

namespace App\Modules\Hr\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A withholding-tax table HR typed in, checked hard before it can reach payroll.
 *
 * The field-level rules below only stop nonsense. The check that earns this class is
 * `assertContinuous()`: the BIR prints each band as "₱X plus Y% of the excess over ₱Z", and X
 * is not free — it is what the bands below it already add up to. Mistype one and the table
 * still looks plausible on screen while quietly mis-withholding for everyone in that band and
 * above. Nothing downstream would catch it.
 */
class SaveTaxTableRequest extends FormRequest
{
    /** Rounding slack, in pesos. The published figures are exact; this only forgives float dust. */
    private const TOLERANCE = 0.01;

    public function authorize(): bool
    {
        return true; // route is gated by role:hr_officer|super_admin
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Two bands minimum: a table with only the exempt tier taxes nobody.
            'bands' => ['required', 'array', 'min:2', 'max:20'],
            // decimal(12,2) — the column tops out below ten billion.
            'bands.*.min_annual' => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
            'bands.*.base_tax' => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
            // A decimal, not a percentage: 0.15 is 15%. 1.0 (100%) is the absurd upper bound.
            'bands.*.rate_on_excess' => ['required', 'numeric', 'min:0', 'max:1'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'bands.min' => 'A tax table needs at least two bands — one exempt, one taxed.',
            'bands.*.rate_on_excess.max' => 'A rate is written as a decimal: 0.15 for 15%.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            // Only run the table-shape checks once the individual fields are numbers; otherwise
            // the arithmetic below would compare against nulls and report confusing nonsense.
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            /** @var list<array<string, mixed>> $bands */
            $bands = $this->input('bands', []);

            $this->assertStartsAtZero($validator, $bands);
            $this->assertAscending($validator, $bands);
            $this->assertContinuous($validator, $bands);
        });
    }

    /**
     * Income below the first floor falls in no band at all and is taxed at nothing. That is
     * the right answer only when the first floor is 0 — otherwise the gap is invisible.
     *
     * @param  list<array<string, mixed>>  $bands
     */
    private function assertStartsAtZero(Validator $validator, array $bands): void
    {
        if ((float) ($bands[0]['min_annual'] ?? -1) !== 0.0) {
            $validator->errors()->add(
                'bands.0.min_annual',
                'The first band must start at 0 — otherwise income below it falls outside the table.',
            );
        }
    }

    /**
     * Each band must start above the one before it. Equal floors would make the resolver's
     * answer depend on row order, and a descending floor is simply a typo.
     *
     * @param  list<array<string, mixed>>  $bands
     */
    private function assertAscending(Validator $validator, array $bands): void
    {
        for ($i = 1; $i < count($bands); $i++) {
            if ((float) $bands[$i]['min_annual'] <= (float) $bands[$i - 1]['min_annual']) {
                $validator->errors()->add(
                    "bands.{$i}.min_annual",
                    'Each band must start above the one before it.',
                );
            }
        }
    }

    /**
     * The arithmetic the BIR table satisfies by construction:
     *
     *     base[i] = base[i-1] + rate[i-1] × (floor[i] − floor[i-1])
     *
     * i.e. the fixed amount on a band is the tax already accumulated by everything under it.
     * Break it and the table jumps or double-counts at a boundary — someone earning ₱1 more
     * than a threshold can owe hundreds less than someone earning ₱1 less.
     *
     * @param  list<array<string, mixed>>  $bands
     */
    private function assertContinuous(Validator $validator, array $bands): void
    {
        if ((float) $bands[0]['base_tax'] !== 0.0) {
            $validator->errors()->add(
                'bands.0.base_tax',
                'The first band starts at 0 income, so its base tax must be 0.',
            );
        }

        for ($i = 1; $i < count($bands); $i++) {
            $previous = $bands[$i - 1];
            $width = (float) $bands[$i]['min_annual'] - (float) $previous['min_annual'];

            // A descending floor already failed above; skip rather than report a bogus expectation.
            if ($width <= 0) {
                continue;
            }

            $expected = round(
                (float) $previous['base_tax'] + (float) $previous['rate_on_excess'] * $width,
                2,
            );

            if (abs($expected - (float) $bands[$i]['base_tax']) > self::TOLERANCE) {
                $validator->errors()->add(
                    "bands.{$i}.base_tax",
                    'This should be '.number_format($expected, 2).' — the tax the bands below it '
                        .'already add up to. Check this figure, or the rate on the band before it.',
                );
            }
        }
    }
}
