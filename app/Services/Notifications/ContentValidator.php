<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Enums\Channel;
use App\Support\Sms\SmsSegmentCalculator;

/**
 * Channel-specific rules for recipients and content.
 */
final readonly class ContentValidator
{
    /** E.164: "+", country code, up to 15 digits in total. */
    private const string E164 = '/^\+[1-9]\d{7,14}$/';

    /** FCM / APNs registration tokens. */
    private const string DEVICE_TOKEN = '/^[A-Za-z0-9:_\-.]{32,4096}$/';

    public function __construct(private SmsSegmentCalculator $segments) {}

    /**
     * @return array<'recipient'|'subject'|'content', string> field => error message
     */
    public function validate(Channel $channel, string $recipient, ?string $subject, string $content): array
    {
        $errors = [];

        $recipientError = $this->recipientError($channel, $recipient);
        if ($recipientError !== null) {
            $errors['recipient'] = $recipientError;
        }

        $subjectError = $this->subjectError($channel, $subject);
        if ($subjectError !== null) {
            $errors['subject'] = $subjectError;
        }

        $contentError = $this->contentError($channel, $content);
        if ($contentError !== null) {
            $errors['content'] = $contentError;
        }

        return $errors;
    }

    private function recipientError(Channel $channel, string $recipient): ?string
    {
        return match ($channel) {
            Channel::Sms => preg_match(self::E164, $recipient) === 1
                ? null
                : 'The recipient must be a phone number in E.164 format, e.g. +905551234567.',
            Channel::Email => filter_var($recipient, FILTER_VALIDATE_EMAIL) !== false
                ? null
                : 'The recipient must be a valid email address.',
            Channel::Push => preg_match(self::DEVICE_TOKEN, $recipient) === 1
                ? null
                : 'The recipient must be a device token (32-4096 characters: letters, digits, ":", "_", "-", ".").',
        };
    }

    private function subjectError(Channel $channel, ?string $subject): ?string
    {
        if ($subject === null) {
            return null;
        }

        if ($channel !== Channel::Email) {
            return 'A subject is only supported for the email channel.';
        }

        $max = $this->limit('content.email.max_subject_length');

        return mb_strlen($subject) > $max ? "The subject may not be longer than {$max} characters." : null;
    }

    private function contentError(Channel $channel, string $content): ?string
    {
        if (trim($content) === '') {
            return 'The content may not be empty.';
        }

        if ($channel === Channel::Sms) {
            $maxSegments = $this->limit('content.sms.max_segments');
            $sms = $this->segments->calculate($content);

            return $sms->segments > $maxSegments
                ? sprintf(
                    'The SMS needs %d segments (%s, %d characters); the maximum is %d segments (%d characters).',
                    $sms->segments,
                    $sms->encoding->value,
                    $sms->units,
                    $maxSegments,
                    $sms->capacityFor($maxSegments),
                )
                : null;
        }

        $max = $this->limit("content.{$channel->value}.max_length");

        return mb_strlen($content) > $max ? "The content may not be longer than {$max} characters." : null;
    }

    private function limit(string $key): int
    {
        $value = config("notifications.{$key}");

        return is_numeric($value) ? (int) $value : 0;
    }
}
