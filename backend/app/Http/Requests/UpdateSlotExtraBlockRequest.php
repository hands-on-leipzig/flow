<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSlotExtraBlockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'link' => 'nullable|string|max:500',
            'duration' => ['sometimes', 'integer', 'min:5', 'max:480', function (string $attribute, mixed $value, \Closure $fail): void {
                if ((int) $value % 5 !== 0) {
                    $fail('Dauer nur in 5-Minuten-Schritten.');
                }
            }],
            'for_explore' => 'sometimes|boolean',
            'for_challenge' => 'sometimes|boolean',
            'active' => 'sometimes|boolean',
        ];
    }
}
