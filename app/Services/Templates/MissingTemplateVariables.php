<?php

declare(strict_types=1);

namespace App\Services\Templates;

use RuntimeException;

final class MissingTemplateVariables extends RuntimeException
{
    /**
     * @param  list<string>  $variables
     */
    public function __construct(public readonly array $variables)
    {
        parent::__construct('Missing template variables: '.implode(', ', $variables));
    }
}
