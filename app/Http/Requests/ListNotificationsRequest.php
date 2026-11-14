<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\Channel;
use App\Enums\NotificationStatus;
use App\Enums\Priority;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Query string filters for GET /notifications.
 *
 * Multi-value filters accept a comma separated list: ?status=queued,retrying
 */
final class ListNotificationsRequest extends FormRequest
{
    private const array LIST_FILTERS = ['status', 'channel', 'priority'];

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'array'],
            'status.*' => [Rule::enum(NotificationStatus::class)],
            'channel' => ['sometimes', 'array'],
            'channel.*' => [Rule::enum(Channel::class)],
            'priority' => ['sometimes', 'array'],
            'priority.*' => [Rule::enum(Priority::class)],
            'batch_id' => ['sometimes', 'uuid'],
            'recipient' => ['sometimes', 'string', 'max:320'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'cursor' => ['sometimes', 'string'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $normalised = [];

        foreach (self::LIST_FILTERS as $filter) {
            $value = $this->query($filter);

            if (is_string($value)) {
                $normalised[$filter] = array_values(array_filter(array_map('trim', explode(',', $value)), fn (string $v): bool => $v !== ''));
            }
        }

        $this->merge($normalised);
    }

    /**
     * @return list<string>
     */
    public function values(string $filter): array
    {
        $values = $this->validated($filter, []);

        return is_array($values) ? array_values(array_filter($values, 'is_string')) : [];
    }

    public function filter(string $key): ?string
    {
        $value = $this->validated($key);

        return is_string($value) ? $value : null;
    }

    public function perPage(): int
    {
        $value = $this->validated('per_page', 25);

        return is_numeric($value) ? (int) $value : 25;
    }
}
