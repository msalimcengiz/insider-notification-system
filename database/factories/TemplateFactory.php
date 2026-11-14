<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Channel;
use App\Models\Template;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Template>
 */
class TemplateFactory extends Factory
{
    protected $model = Template::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->slug(3),
            'channel' => Channel::Sms,
            'subject' => null,
            'body' => 'Hi {{ name }}, your code is {{ code }}.',
        ];
    }
}
