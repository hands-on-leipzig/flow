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
        return [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'link' => 'nullable|string|max:500',
            'duration' => ['required', 'integer', 'min:5', 'max:480', function (string $attribute, mixed $value, \Closure $fail): void {
                if ((int) $value % 5 !== 0) {
                    $fail('Dauer nur in 5-Minuten-Schritten.');
                }
            }],
            'for_explore' => 'required|boolean',
            'for_challenge' => 'required|boolean',
            'active' => 'sometimes|boolean',
        ];
    }
}
