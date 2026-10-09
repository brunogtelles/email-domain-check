<?php

namespace App\Http\Requests;

use App\Enums\CheckType;
use App\Services\DomainCheck\BulkInputParser;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreCheckBatchRequest extends FormRequest
{
    /**
     * @var array{entries: list<array{input: string, domain: string|null, selector: string|null}>, duplicates: int}|null
     */
    private ?array $parsed = null;

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
            'type' => ['required', Rule::enum(CheckType::class)],
            'file' => ['required', 'file', 'extensions:csv,txt', 'max:'.config('domaincheck.bulk.max_file_kb')],
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
                if ($validator->errors()->has('file')) {
                    return;
                }

                $contents = (string) $this->file('file')->get();

                if (str_contains($contents, "\0")) {
                    $validator->errors()->add('file', 'The file must be a plain text CSV or TXT file.');

                    return;
                }

                $this->parsed = app(BulkInputParser::class)->parse($contents);
                $count = count($this->parsed['entries']);
                $maxRows = (int) config('domaincheck.bulk.max_rows');

                if ($count === 0) {
                    $validator->errors()->add('file', 'No domains or e-mail addresses were found in the file.');
                } elseif ($count > $maxRows) {
                    $validator->errors()->add('file', "The file has {$count} unique entries; the limit is {$maxRows}.");
                }
            },
        ];
    }

    /**
     * @return array{entries: list<array{input: string, domain: string|null, selector: string|null}>, duplicates: int}
     */
    public function parsedEntries(): array
    {
        return $this->parsed ?? ['entries' => [], 'duplicates' => 0];
    }
}
