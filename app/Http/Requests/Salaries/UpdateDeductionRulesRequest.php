<?php

namespace App\Http\Requests\Salaries;

use App\Support\PayrollDeductionSettings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateDeductionRulesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [
            'late.basis' => [
                'required',
                Rule::in([
                    PayrollDeductionSettings::BASIS_CHECK_IN,
                    PayrollDeductionSettings::BASIS_LATE_THRESHOLD,
                ]),
            ],
            'absent' => ['required', 'array'],
            'absent.enabled' => ['required', 'boolean'],
            'absent.amount' => ['required', 'integer', 'min:0', 'max:'.PayrollDeductionSettings::MAX_AMOUNT],
        ];

        foreach (PayrollDeductionSettings::TIERED_GROUPS as $group) {
            $rules[$group] = ['required', 'array'];
            $rules["{$group}.enabled"] = ['required', 'boolean'];
            // 'present' rather than 'required': an empty ladder is a legitimate
            // payload for a group that is switched off, and required rejects [].
            $rules["{$group}.tiers"] = ['present', 'array', 'max:'.PayrollDeductionSettings::MAX_TIERS];
            $rules["{$group}.tiers.*.from_minutes"] = [
                'required', 'integer', 'min:1', 'max:'.PayrollDeductionSettings::MAX_FROM_MINUTES,
            ];
            $rules["{$group}.tiers.*.amount"] = [
                'required', 'integer', 'min:0', 'max:'.PayrollDeductionSettings::MAX_AMOUNT,
            ];
        }

        return $rules;
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                foreach (PayrollDeductionSettings::TIERED_GROUPS as $group) {
                    $this->validateLadder($validator, $group);
                }
            },
        ];
    }

    /**
     * A ladder must be usable: an enabled group needs at least one rung, and no
     * two rungs may start at the same minute — the second would be unreachable
     * because the deeper tier always wins.
     */
    private function validateLadder(Validator $validator, string $group): void
    {
        if (! $this->boolean("{$group}.enabled")) {
            return;
        }

        /** @var array<int, array{from_minutes?: mixed}> $tiers */
        $tiers = $this->input("{$group}.tiers", []);

        if (! is_array($tiers) || $tiers === []) {
            $validator->errors()->add(
                "{$group}.tiers",
                'Tambahkan minimal satu tingkat potongan, atau nonaktifkan aturan ini.',
            );

            return;
        }

        $seen = [];

        foreach ($tiers as $index => $tier) {
            $from = is_array($tier) ? ($tier['from_minutes'] ?? null) : null;

            if ($from === null || ! is_numeric($from)) {
                continue;
            }

            $from = (int) $from;

            if (isset($seen[$from])) {
                $validator->errors()->add(
                    "{$group}.tiers.{$index}.from_minutes",
                    "Tingkat {$from} menit sudah ada. Gunakan menit yang berbeda.",
                );

                continue;
            }

            $seen[$from] = true;
        }
    }
}
