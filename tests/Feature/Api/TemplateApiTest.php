<?php

declare(strict_types=1);

use App\Models\Notification;
use App\Models\Template;

it('creates a template and lists its variables', function (): void {
    $this->postJson('/api/v1/templates', [
        'name' => 'order-shipped',
        'channel' => 'email',
        'subject' => 'Order {{ order_id }} shipped',
        'body' => 'Hi {{ name }}, order {{ order_id }} is on its way.',
    ])->assertCreated()
        ->assertJsonPath('data.name', 'order-shipped')
        ->assertJsonPath('data.variables', ['order_id', 'name']);
});

it('validates templates', function (array $payload, string $field): void {
    Template::factory()->create(['name' => 'taken']);

    $this->postJson('/api/v1/templates', [
        'name' => 'valid-name', 'channel' => 'sms', 'body' => 'Hi {{ name }}', ...$payload,
    ])->assertUnprocessable()->assertJsonValidationErrors([$field]);
})->with([
    'duplicate name' => [['name' => 'taken'], 'name'],
    'bad name' => [['name' => 'Has Spaces'], 'name'],
    'unknown channel' => [['channel' => 'fax'], 'channel'],
    'subject on sms' => [['subject' => 'Hi'], 'subject'],
    'broken placeholder' => [['body' => 'Hi {{ first name }}'], 'body'],
    'missing body' => [['body' => ''], 'body'],
]);

it('shows, lists and filters templates', function (): void {
    $sms = Template::factory()->create(['name' => 'a-sms']);
    Template::factory()->create(['name' => 'b-push', 'channel' => 'push']);

    $this->getJson("/api/v1/templates/{$sms->id}")->assertOk()->assertJsonPath('data.name', 'a-sms');
    $this->getJson('/api/v1/templates')->assertOk()->assertJsonCount(2, 'data');
    $this->getJson('/api/v1/templates?channel=push')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'b-push');
});

it('replaces a template, keeping its name unique against others only', function (): void {
    $template = Template::factory()->create(['name' => 'otp']);

    $this->putJson("/api/v1/templates/{$template->id}", [
        'name' => 'otp', 'channel' => 'sms', 'body' => 'Code: {{ code }}',
    ])->assertOk()->assertJsonPath('data.body', 'Code: {{ code }}');
});

it('deletes a template without touching rendered notifications', function (): void {
    $template = Template::factory()->create();
    $notification = Notification::factory()->create(['template_id' => $template->id, 'content' => 'Rendered']);

    $this->deleteJson("/api/v1/templates/{$template->id}")->assertNoContent();

    expect(Template::query()->count())->toBe(0)
        ->and($notification->refresh()->template_id)->toBeNull()
        ->and($notification->content)->toBe('Rendered');
});

it('previews a template', function (): void {
    $template = Template::factory()->create(['body' => 'Hi {{ name }}']);

    $this->postJson("/api/v1/templates/{$template->id}/preview", ['variables' => ['name' => 'Ada']])
        ->assertOk()
        ->assertJsonPath('data.content', 'Hi Ada');

    $this->postJson("/api/v1/templates/{$template->id}/preview", ['variables' => []])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['variables']);
});
