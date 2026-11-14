<?php

declare(strict_types=1);

namespace App\Http\Requests\Templates;

use App\Enums\Channel;
use App\Models\Template;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Create (POST) or replace (PUT) a template.
 */
final class StoreTemplateRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $template = $this->route('template');
        $ignoreId = $template instanceof Template ? $template->id : null;

        return [
            'name' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9]+(?:[-_.][a-z0-9]+)*$/', Rule::unique('templates', 'name')->ignore($ignoreId)],
            'channel' => ['required', Rule::enum(Channel::class)],
            'subject' => ['nullable', 'string', 'max:'.(int) config('notifications.content.email.max_subject_length'), 'prohibited_unless:channel,email'],
            'body' => ['required', 'string', 'max:100000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.regex' => 'The name may only contain lowercase letters, digits and "-", "_" or "." separators (e.g. "order-shipped").',
            'subject.prohibited_unless' => 'A subject is only supported for email templates.',
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                foreach (['body', 'subject'] as $field) {
                    $value = $this->input($field);

                    // A "{{" that does not form a valid placeholder is almost certainly a typo.
                    if (is_string($value) && preg_match_all('/\{\{/', $value) !== preg_match_all('/\{\{\s*[A-Za-z_][A-Za-z0-9_]*\s*\}\}/', $value)) {
                        $validator->errors()->add($field, 'Placeholders must look like {{ name }} (letters, digits and underscores).');
                    }
                }
            },
        ];
    }
}
