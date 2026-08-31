<?php

namespace App\Http\Requests\Salaries;

/**
 * A shift's own deduction ladders.
 *
 * Identical to the installation-wide rules, plus the flag that decides whether
 * this shift overrides them at all.
 */
class UpdateShiftDeductionRulesRequest extends UpdateDeductionRulesRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = ['overrides' => ['required', 'boolean']];

        // A shift that follows the global rules submits no ladders of its own,
        // so there is nothing left to validate.
        if (! $this->boolean('overrides')) {
            return $rules;
        }

        return [...$rules, ...parent::rules()];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return $this->boolean('overrides') ? parent::after() : [];
    }
}
