<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSlotExtraBlockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $allowedPrograms = implode(',', [
            \App\Enums\FirstProgram::JOINT->value,
            \App\Enums\FirstProgram::DISCOVER->value,
            \App\Enums\FirstProgram::EXPLORE->value,
            \App\Enums\FirstProgram::CHALLENGE->value,
            \App\Enums\FirstProgram::FUTURE_8->value,
        ]);

        return [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'link' => 'nullable|string|max:500',
            'duration' => ['required', 'integer', 'min:5', 'max:480', function (string $attribute, mixed $value, \Closure $fail): void {
                if ((int) $value % 5 !== 0) {
                    $fail('Dauer nur in 5-Minuten-Schritten.');
                }
            }],
            'first_program' => "required|integer|in:{$allowedPrograms}",
            'active' => 'sometimes|boolean',
        ];
    }
}
