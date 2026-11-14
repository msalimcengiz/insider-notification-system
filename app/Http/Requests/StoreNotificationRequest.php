<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\DTOs\NotificationData;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use LogicException;

final class StoreNotificationRequest extends FormRequest
{
    use ReadsIdempotencyKey;

    private ?NotificationData $data = null;

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return NotificationPayload::rules();
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(NotificationPayload $payload): array
    {
        return [
            function (Validator $validator) use ($payload): void {
                $parsed = $payload->parse($validator, ['item' => $this->all()], fn (): string => '');
                $this->data = $parsed['item'] ?? null;
            },
        ];
    }

    public function toData(): NotificationData
    {
        return $this->data ?? throw new LogicException('The request has not been validated.');
    }
}
