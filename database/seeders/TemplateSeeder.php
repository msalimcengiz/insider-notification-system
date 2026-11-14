<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Channel;
use App\Models\Template;
use Illuminate\Database\Seeder;

/**
 * A few ready-to-use templates so the API can be tried right after setup.
 */
class TemplateSeeder extends Seeder
{
    public function run(): void
    {
        $templates = [
            [
                'name' => 'otp-sms',
                'channel' => Channel::Sms,
                'subject' => null,
                'body' => 'Your verification code is {{ code }}. It expires in {{ minutes }} minutes.',
            ],
            [
                'name' => 'flash-sale-push',
                'channel' => Channel::Push,
                'subject' => null,
                'body' => '{{ name }}, the flash sale has started: {{ discount }}% off for the next hour!',
            ],
            [
                'name' => 'order-shipped-email',
                'channel' => Channel::Email,
                'subject' => 'Your order {{ order_id }} is on its way',
                'body' => 'Hi {{ name }}, your order {{ order_id }} has been shipped and will arrive on {{ eta }}.',
            ],
        ];

        foreach ($templates as $template) {
            Template::query()->updateOrCreate(['name' => $template['name']], $template);
        }
    }
}
