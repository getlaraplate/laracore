<?php

declare(strict_types=1);

namespace Modules\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Core\Rules\PreferencesBag;

/**
 * Self-service update of the authenticated user's own UI preferences, language and timezone. No target
 * id is accepted — the endpoint always writes the current session user's row.
 * Each key of `preferences` is a namespace: the write replaces it, or removes it when null.
 * `lang` and `timezone` are the user's own columns: a value sets it, null clears it, and a key left
 * out is not touched. At least one of the three must be sent.
 *
 * @property array<string, mixed> $preferences
 */
final class UpdatePreferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array|string>
     */
    public function rules(): array
    {
        return [
            'preferences' => ['sometimes', 'array', new PreferencesBag],
            'lang' => ['sometimes', 'nullable', 'string', Rule::in(config('app.available_locales', translations()))],
            'timezone' => ['sometimes', 'nullable', 'string', 'timezone:all'],
        ];
    }

    /**
     * @return array<int, \Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->hasAny(['preferences', 'lang', 'timezone'])) {
                    $validator->errors()->add('preferences', 'Send preferences, lang or timezone.');
                }
            },
        ];
    }
}
