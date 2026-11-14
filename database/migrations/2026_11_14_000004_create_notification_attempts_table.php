<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('notification_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('attempt_number');
            $table->string('outcome', 32);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->text('error_message')->nullable();
            $table->jsonb('response_body')->nullable();
            $table->unsignedInteger('duration_ms');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['notification_id', 'attempt_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_attempts');
    }
};
