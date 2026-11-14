<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Template;
use App\Services\Templates\TemplateRenderer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Template
 */
final class TemplateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $renderer = app(TemplateRenderer::class);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'channel' => $this->channel,
            'subject' => $this->subject,
            'body' => $this->body,
            'variables' => array_values(array_unique([
                ...$renderer->placeholders($this->subject ?? ''),
                ...$renderer->placeholders($this->body),
            ])),
            'created_at' => $this->created_at->toIso8601ZuluString(),
            'updated_at' => $this->updated_at->toIso8601ZuluString(),
        ];
    }
}
