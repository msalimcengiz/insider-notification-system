<?php

declare(strict_types=1);

use App\Services\Templates\MissingTemplateVariables;
use App\Services\Templates\TemplateRenderer;

it('substitutes variables with or without spaces', function (): void {
    $output = (new TemplateRenderer)->render('Hi {{ name }}, code {{code}}.', ['name' => 'Ada', 'code' => 1234]);

    expect($output)->toBe('Hi Ada, code 1234.');
});

it('substitutes repeated placeholders', function (): void {
    expect((new TemplateRenderer)->render('{{ a }}-{{ a }}', ['a' => 'x']))->toBe('x-x');
});

it('ignores extra variables', function (): void {
    expect((new TemplateRenderer)->render('Hi {{ name }}', ['name' => 'Ada', 'unused' => true]))->toBe('Hi Ada');
});

it('reports every missing variable', function (): void {
    try {
        (new TemplateRenderer)->render('{{ a }} {{ b }} {{ c }}', ['b' => 1]);
        $this->fail('Expected an exception');
    } catch (MissingTemplateVariables $e) {
        expect($e->variables)->toBe(['a', 'c']);
    }
});

it('does not evaluate values as placeholders', function (): void {
    expect((new TemplateRenderer)->render('{{ a }}', ['a' => '{{ b }}']))->toBe('{{ b }}');
});
