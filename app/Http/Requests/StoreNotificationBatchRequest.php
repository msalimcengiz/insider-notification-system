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
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $max = (int) config('notifications.batch.max_size');

        return [
            'notifications' => ['required', 'array', 'list', 'min:1', "max:{$max}"],
            ...NotificationPayload::rules('notifications.*.', withItemIdempotencyKey: true),
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
