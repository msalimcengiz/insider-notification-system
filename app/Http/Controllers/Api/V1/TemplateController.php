<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Templates\StoreTemplateRequest;
use App\Http\Resources\TemplateResource;
use App\Models\Template;
use App\Services\Templates\MissingTemplateVariables;
use App\Services\Templates\TemplateRenderer;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

#[Group('Templates', weight: 3)]
final class TemplateController extends Controller
{
    /**
     * List templates.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $channel = $request->query('channel');

        return TemplateResource::collection(
            Template::query()
                ->when(is_string($channel), fn ($q) => $q->where('channel', $channel))
                ->orderBy('name')
                ->paginate(50)
                ->withQueryString(),
        );
    }

    /**
     * Create a template.
     *
     * Use it when creating notifications with `"template": "<name>"` and
     * `"variables": {...}`. Placeholders look like `{{ name }}`.
     *
     * @status 201
     */
    public function store(StoreTemplateRequest $request): JsonResponse
    {
        return TemplateResource::make(Template::query()->create($request->validated()))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Get a template.
     */
    public function show(Template $template): TemplateResource
    {
        return TemplateResource::make($template);
    }

    /**
     * Replace a template.
     *
     * Notifications already created keep the content they were rendered with.
     */
    public function update(StoreTemplateRequest $request, Template $template): TemplateResource
    {
        $template->update($request->validated());

        return TemplateResource::make($template);
    }

    /**
     * Delete a template.
     *
     * Existing notifications keep their rendered content.
     */
    public function destroy(Template $template): Response
    {
        $template->delete();

        return response()->noContent();
    }

    /**
     * Preview a template.
     *
     * Renders the template with the given variables without creating anything.
     */
    public function preview(Request $request, Template $template, TemplateRenderer $renderer): JsonResponse
    {
        $variables = $request->validate(['variables' => ['sometimes', 'array']])['variables'] ?? [];

        try {
            /** @var array<string, scalar|null> $variables */
            return response()->json(['data' => [
                'subject' => $template->subject !== null ? $renderer->render($template->subject, $variables) : null,
                'content' => $renderer->render($template->body, $variables),
            ]]);
        } catch (MissingTemplateVariables $e) {
            throw ValidationException::withMessages([
                'variables' => 'Missing template variables: '.implode(', ', $e->variables).'.',
            ]);
        }
    }
}
