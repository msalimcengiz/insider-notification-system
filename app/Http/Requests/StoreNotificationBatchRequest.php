<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\DTOs\NotificationData;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class StoreNotificationBatchRequest extends FormRequest
{
    use ReadsIdempotencyKey;

    /** @var list<NotificationData> */
    private array $data = [];

    /**
     * The full request shape. This is what the API documentation shows, and
     * NotificationItemShape enforces exactly these item rules (parity test).
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            ...$this->topLevelRules(),
            ...NotificationPayload::rules('notifications.*.', withItemIdempotencyKey: true),
        ];
    }

    /**
     * What actually runs through Laravel's validator: only the top-level
     * rules. Expanding `notifications.*.*` for 1000 items costs ~450 ms, so
     * the items are checked by NotificationItemShape in after() instead.
     *
     * @return array<string, list<mixed>>
     */
    protected function validationRules(): array
    {
        return $this->topLevelRules();
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function topLevelRules(): array
    {
        $max = (int) config('notifications.batch.max_size');

        return [
            'notifications' => ['required', 'array', 'list', 'min:1', "max:{$max}"],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(NotificationPayload $payload): array
    {
        return [
            function (Validator $validator) use ($payload): void {
                if ($validator->errors()->has('notifications')) {
                    return;
                }

                $items = $this->input('notifications');
                $items = is_array($items) ? $items : [];

                foreach ($items as $index => $item) {
                    $prefix = "notifications.{$index}.";

                    if (! is_array($item)) {
                        $validator->errors()->add("notifications.{$index}", "The notifications.{$index} field must be an object.");

                        continue;
                    }

                    foreach (NotificationItemShape::errors($item, $prefix, withItemIdempotencyKey: true) as $field => $message) {
                        $validator->errors()->add($prefix.$field, $message);
                    }
                }

                $this->data = array_values($payload->parse(
                    $validator,
                    $items,
                    fn (int|string $index): string => "notifications.{$index}.",
                ));

                $this->rejectDuplicateKeys($validator, $items);
            },
        ];
    }

    /**
     * @return list<NotificationData>
     */
    public function toData(): array
    {
        return $this->data;
    }

    /**
     * The same idempotency key twice inside one batch is a client bug.
     *
     * @param  array<int|string, mixed>  $items
     */
    private function rejectDuplicateKeys(Validator $validator, array $items): void
    {
        $seen = [];

        foreach ($items as $index => $item) {
            $key = is_array($item) ? ($item['idempotency_key'] ?? null) : null;

            if (! is_string($key) || $key === '') {
                continue;
            }

            if (isset($seen[$key])) {
                $validator->errors()->add(
                    "notifications.{$index}.idempotency_key",
                    "The idempotency key is already used by notifications.{$seen[$key]}.",
                );
            }

            $seen[$key] ??= $index;
        }
    }
}
