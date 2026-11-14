<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('templates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 100)->unique();
            $table->string('channel', 16);
            $table->string('subject')->nullable();
            $table->text('body');
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('templates');
    }
};
