<?php

declare(strict_types=1);

it('describes the service at the root URL', function (): void {
    $this->getJson('/')
        ->assertOk()
        ->assertJsonStructure(['name', 'docs', 'health']);
});
