<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\Channel;
use App\Enums\Priority;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Hand-written equivalent of NotificationPayload::rules() for batch items.
 *
 * Laravel's validator expands `notifications.*.field` rules for every item
 * and costs ~0.45 ms per item: about 450 ms of a 1000-item batch request.
 * These checks do the same in a few milliseconds. The single endpoint keeps
 * the declarative rules, and a parity test runs both against the same
 * payloads so they cannot drift apart.
 */
final class NotificationItemShape
{
    /**
     * @param  array<mixed>  $item
     * @return array<string, string> field => message
     */
    public static function errors(array $item, string $attributePrefix = '', bool $withItemIdempotencyKey = false): array
    {
        $errors = [];
        $attr = fn (string $field): string => $attributePrefix.$field;
        $present = fn (string $field): bool => array_key_exists($field, $item) && $item[$field] !== null && $item[$field] !== '';

        // channel: required, enum
        if (! $present('channel')) {
            $errors['channel'] = self::message('validation.required', ['attribute' => $attr('channel')]);
        } elseif (! is_string($item['channel']) || Channel::tryFrom($item['channel']) === null) {
            $errors['channel'] = self::message('validation.enum', ['attribute' => $attr('channel')]);
        }

        // priority: sometimes, enum
        if (array_key_exists('priority', $item) && (! is_string($item['priority']) || Priority::tryFrom($item['priority']) === null)) {
            $errors['priority'] = self::message('validation.enum', ['attribute' => $attr('priority')]);
        }

        // recipient: required, string, max:320
        if (! $present('recipient')) {
            $errors['recipient'] = self::message('validation.required', ['attribute' => $attr('recipient')]);
        } elseif (! is_string($item['recipient'])) {
            $errors['recipient'] = self::message('validation.string', ['attribute' => $attr('recipient')]);
        } elseif (mb_strlen($item['recipient']) > 320) {
            $errors['recipient'] = self::message('validation.max.string', ['attribute' => $attr('recipient'), 'max' => 320]);
        }

        // subject: nullable, string
        if ($present('subject') && ! is_string($item['subject'])) {
            $errors['subject'] = self::message('validation.string', ['attribute' => $attr('subject')]);
        }

        // template: nullable, string, max:100, prohibits:content
        if ($present('template')) {
            if (! is_string($item['template'])) {
                $errors['template'] = self::message('validation.string', ['attribute' => $attr('template')]);
            } elseif (mb_strlen($item['template']) > 100) {
                $errors['template'] = self::message('validation.max.string', ['attribute' => $attr('template'), 'max' => 100]);
            } elseif ($present('content')) {
                $errors['template'] = self::message('validation.prohibits', ['attribute' => $attr('template'), 'other' => $attr('content')]);
            }
        }

        // content: required_without:template, nullable, string
        if (! $present('content') && ! $present('template')) {
            $errors['content'] = self::message('validation.required_without', ['attribute' => $attr('content'), 'values' => $attr('template')]);
        } elseif ($present('content') && ! is_string($item['content'])) {
            $errors['content'] = self::message('validation.string', ['attribute' => $attr('content')]);
        }

        // variables: nullable, array
        if ($present('variables') && ! is_array($item['variables'])) {
            $errors['variables'] = self::message('validation.array', ['attribute' => $attr('variables')]);
        }

        // scheduled_at: nullable, date, after:now, before:+N days
        if ($present('scheduled_at')) {
            $error = self::scheduledAtError($item['scheduled_at'], $attr('scheduled_at'));
            if ($error !== null) {
                $errors['scheduled_at'] = $error;
            }
        }

        // idempotency_key: nullable, string, max:255
        if ($withItemIdempotencyKey && $present('idempotency_key')) {
            if (! is_string($item['idempotency_key'])) {
                $errors['idempotency_key'] = self::message('validation.string', ['attribute' => $attr('idempotency_key')]);
            } elseif (strlen($item['idempotency_key']) > 255) {
                $errors['idempotency_key'] = self::message('validation.max.string', ['attribute' => $attr('idempotency_key'), 'max' => 255]);
            }
        }

        return $errors;
    }

    private static function scheduledAtError(mixed $value, string $attribute): ?string
    {
        if (! is_string($value) || strtotime($value) === false) {
            return self::message('validation.date', ['attribute' => $attribute]);
        }

        try {
            $at = CarbonImmutable::parse($value);
        } catch (Throwable) {
            return self::message('validation.date', ['attribute' => $attribute]);
        }

        if (! $at->isAfter(now())) {
            return self::message('validation.after', ['attribute' => $attribute, 'date' => 'now']);
        }

        $limit = now()->addDays((int) config('notifications.max_schedule_days'));

        return $at->isBefore($limit)
            ? null
            : self::message('validation.before', ['attribute' => $attribute, 'date' => $limit->toIso8601String()]);
    }

    /**
     * @param  array<string, string|int>  $replace
     */
    private static function message(string $key, array $replace): string
    {
        $message = trans($key, $replace);

        return is_string($message) ? $message : $key;
    }
}
