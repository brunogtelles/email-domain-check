<?php

namespace App\Http\Requests;

use App\Services\DomainCheck\DomainNormalizer;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SingleCheckRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'input' => ['required', 'string', 'max:320'],
            'selector' => ['nullable', 'string', 'max:100', 'regex:/^[a-z0-9._-]+$/i'],
            'all_records' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Get the "after" validation callables for the request.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $validator->errors()->has('input') && $this->domain() === null) {
                    $validator->errors()->add('input', 'Enter a valid domain (example.com) or e-mail address (user@example.com).');
                }
            },
        ];
    }

    public function domain(): ?string
    {
        return DomainNormalizer::normalize((string) $this->input('input'));
    }

    /**
     * @return array{selectors: list<string>, all_records: bool}
     */
    public function checkOptions(): array
    {
        return [
            'selectors' => array_values(array_filter([$this->input('selector')])),
            'all_records' => $this->boolean('all_records'),
        ];
    }
}
