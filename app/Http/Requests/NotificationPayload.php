<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\DTOs\NotificationData;
use App\Enums\Channel;
use App\Enums\Priority;
use App\Models\Template;
use App\Services\Notifications\ContentValidator;
use App\Services\Templates\MissingTemplateVariables;
use App\Services\Templates\TemplateRenderer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validation shared by the single and the batch endpoint.
 *
 * Validation happens in two passes:
 *  1. Declarative rules (types, enums, dates) for every item.
 *  2. For items that passed (1): resolve the template, render it, then apply
 *     the channel-specific recipient and content rules to the final text.
 *     The resulting DTOs are what gets persisted.
 */
final readonly class NotificationPayload
{
    public function __construct(
        private ContentValidator $contentValidator,
        private TemplateRenderer $renderer,
    ) {}

    /**
     * Rules for one notification, with every key prefixed (e.g. "notifications.*.").
     *
     * @return array<string, list<mixed>>
     */
    public static function rules(string $prefix = '', bool $withItemIdempotencyKey = false): array
    {
        $maxScheduleDays = (int) config('notifications.max_schedule_days');

        $rules = [
            "{$prefix}channel" => ['required', Rule::enum(Channel::class)],
            "{$prefix}priority" => ['sometimes', Rule::enum(Priority::class)],
            "{$prefix}recipient" => ['required', 'string', 'max:320'],
            "{$prefix}subject" => ['nullable', 'string'],
            "{$prefix}content" => ['required_without:'.$prefix.'template', 'nullable', 'string'],
            "{$prefix}template" => ['nullable', 'string', 'max:100', 'prohibits:'.$prefix.'content'],
            "{$prefix}variables" => ['nullable', 'array'],
            "{$prefix}scheduled_at" => [
                'nullable',
                'date',
                'after:now',
                'before:'.now()->addDays($maxScheduleDays)->toIso8601String(),
            ],
        ];

        if ($withItemIdempotencyKey) {
            $rules["{$prefix}idempotency_key"] = ['nullable', 'string', 'max:255'];
        }

        return $rules;
    }

    /**
     * Second validation pass. Adds errors to the validator and returns the
     * DTOs of the items that are valid, keyed like $items.
     *
     * @param  array<int|string, mixed>  $items  item key => raw item
     * @param  callable(int|string): string  $prefix  item key => error key prefix
     * @return array<int|string, NotificationData>
     */
    public function parse(Validator $validator, array $items, callable $prefix): array
    {
        $templates = $this->loadTemplates($items);
        $parsed = [];

        foreach ($items as $key => $item) {
            $p = $prefix($key);

            if (! is_array($item) || $validator->errors()->hasAny($this->fields($p))) {
                continue;
            }

            $data = $this->parseItem($item, $templates, $p, $validator);

            if ($data !== null) {
                $parsed[$key] = $data;
            }
        }

        return $parsed;
    }

    /**
     * @param  array<mixed>  $item
     * @param  Collection<string, Template>  $templates
     */
    private function parseItem(array $item, Collection $templates, string $p, Validator $validator): ?NotificationData
    {
        $channel = Channel::from($this->string($item, 'channel') ?? '');
        $subject = $this->string($item, 'subject');
        $content = $this->string($item, 'content');
        $templateName = $this->string($item, 'template');
        $template = null;

        $variables = $this->variables($item);
        if ($variables === null) {
            $validator->errors()->add("{$p}variables", 'The variables must be an object of scalar values.');

            return null;
        }

        if ($templateName !== null) {
            $template = $templates->get($templateName);

            if ($template === null) {
                $validator->errors()->add("{$p}template", "The template \"{$templateName}\" does not exist.");

                return null;
            }

            if ($template->channel !== $channel) {
                $validator->errors()->add("{$p}template", "The template \"{$templateName}\" is for the {$template->channel->value} channel.");

                return null;
            }

            try {
                $content = $this->renderer->render($template->body, $variables);
                if ($subject === null && $template->subject !== null) {
                    $subject = $this->renderer->render($template->subject, $variables);
                }
            } catch (MissingTemplateVariables $e) {
                $validator->errors()->add("{$p}variables", 'Missing template variables: '.implode(', ', $e->variables).'.');

                return null;
            }
        }

        $recipient = $this->string($item, 'recipient') ?? '';
        $errors = $this->contentValidator->validate($channel, $recipient, $subject, $content ?? '');

        foreach ($errors as $field => $message) {
            $validator->errors()->add($p.$field, $message);
        }

        if ($errors !== []) {
            return null;
        }

        $scheduledAt = $this->string($item, 'scheduled_at');

        return new NotificationData(
            channel: $channel,
            priority: Priority::tryFrom($this->string($item, 'priority') ?? '') ?? Priority::Normal,
            recipient: $recipient,
            content: (string) $content,
            subject: $subject,
            templateId: $template?->id,
            templateVariables: $template !== null ? $variables : null,
            scheduledAt: $scheduledAt !== null ? CarbonImmutable::parse($scheduledAt)->utc() : null,
            idempotencyKey: $this->string($item, 'idempotency_key'),
        );
    }

    /**
     * One query for every template referenced by the request.
     *
     * @param  array<int|string, mixed>  $items
     * @return Collection<string, Template>
     */
    private function loadTemplates(array $items): Collection
    {
        $names = collect($items)
            ->map(fn (mixed $item): mixed => is_array($item) ? ($item['template'] ?? null) : null)
            ->filter(fn (mixed $name): bool => is_string($name) && $name !== '')
            ->unique()
            ->values();

        if ($names->isEmpty()) {
            return new Collection;
        }

        return Template::query()->whereIn('name', $names->all())->get()->keyBy('name');
    }

    /**
     * @return list<string>
     */
    private function fields(string $p): array
    {
        return array_map(
            fn (string $field): string => $p.$field,
            ['channel', 'priority', 'recipient', 'subject', 'content', 'template', 'variables', 'scheduled_at', 'idempotency_key'],
        );
    }

    /**
     * @param  array<mixed>  $item
     */
    private function string(array $item, string $key): ?string
    {
        $value = $item[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param  array<mixed>  $item
     * @return array<string, scalar|null>|null null when the shape is invalid
     */
    private function variables(array $item): ?array
    {
        $variables = $item['variables'] ?? [];

        if (! is_array($variables)) {
            return null;
        }

        foreach ($variables as $name => $value) {
            if (! is_string($name) || ! (is_scalar($value) || $value === null)) {
                return null;
            }
        }

        /** @var array<string, scalar|null> $variables */
        return $variables;
    }
}
