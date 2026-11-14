<?php

declare(strict_types=1);

namespace App\Services\Templates;

/**
 * Substitutes {{ variable }} placeholders. Plain text only: notification
 * bodies are not HTML, so values are inserted verbatim.
 */
final class TemplateRenderer
{
    private const string PLACEHOLDER = '/\{\{\s*([A-Za-z_][A-Za-z0-9_]*)\s*\}\}/';

    /**
     * @param  array<string, scalar|null>  $variables
     *
     * @throws MissingTemplateVariables
     */
    public function render(string $template, array $variables): string
    {
        $missing = array_values(array_diff($this->placeholders($template), array_keys($variables)));

        if ($missing !== []) {
            throw new MissingTemplateVariables($missing);
        }

        return (string) preg_replace_callback(
            self::PLACEHOLDER,
            fn (array $match): string => (string) $variables[$match[1]],
            $template,
        );
    }

    /**
     * Unique placeholder names in order of appearance.
     *
     * @return list<string>
     */
    public function placeholders(string $template): array
    {
        preg_match_all(self::PLACEHOLDER, $template, $matches);

        return array_values(array_unique($matches[1]));
    }
}
