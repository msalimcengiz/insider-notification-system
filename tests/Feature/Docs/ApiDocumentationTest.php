<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;

beforeEach(function (): void {
    // Docs are open in the local environment only; tests run as "testing".
    Gate::define('viewApiDocs', fn ($user = null): bool => true);
});

it('serves Swagger UI with locally bundled assets', function (): void {
    $html = $this->get('/docs/api')->assertOk()->getContent();

    expect($html)->toContain('SwaggerUIBundle(')
        ->toContain('vendor/swagger-ui/swagger-ui-bundle.js')
        ->toContain('vendor/swagger-ui/swagger-ui.css')
        ->toContain(json_encode(route('scramble.docs.document'))) // @json escapes the slashes
        ->not->toContain('unpkg.com')
        ->not->toContain('cdn.jsdelivr.net');

    expect(public_path('vendor/swagger-ui/swagger-ui-bundle.js'))->toBeFile()
        ->and(public_path('vendor/swagger-ui/swagger-ui.css'))->toBeFile()
        ->and(public_path('vendor/swagger-ui/LICENSE'))->toBeFile();
});

it('serves the OpenAPI 3.1 document Swagger UI loads', function (): void {
    $spec = $this->getJson('/docs/api.json')->assertOk()->json();

    expect($spec['openapi'])->toStartWith('3.1')
        ->and(array_keys($spec['paths']))->toContain('/v1/notifications', '/v1/notifications/batch', '/v1/health');
});
