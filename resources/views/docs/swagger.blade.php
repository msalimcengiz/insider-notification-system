<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $config->get('ui.title') ?? config('app.name').' - API Docs' }}</title>
    {{-- Swagger UI 5.17.14 (swagger-ui-dist, Apache-2.0), bundled locally: public/vendor/swagger-ui --}}
    <link rel="stylesheet" href="{{ asset('vendor/swagger-ui/swagger-ui.css') }}">
    <link rel="icon" type="image/png" href="{{ asset('vendor/swagger-ui/favicon-32x32.png') }}" sizes="32x32">
    <style>
        body { margin: 0; background: #fafafa; }
    </style>
</head>
<body>
<div id="swagger-ui"></div>
<script src="{{ asset('vendor/swagger-ui/swagger-ui-bundle.js') }}"></script>
<script>
    // The OpenAPI 3.1 document Scramble generates from the code (also committed as openapi.json).
    window.ui = SwaggerUIBundle({
        url: @json(route('scramble.docs.document')),
        dom_id: '#swagger-ui',
        deepLinking: true,
        presets: [SwaggerUIBundle.presets.apis],
        layout: 'BaseLayout',
        tryItOutEnabled: true,
        displayRequestDuration: true,
        defaultModelsExpandDepth: 0,
    });
</script>
</body>
</html>
