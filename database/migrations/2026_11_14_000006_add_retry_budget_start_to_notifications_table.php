<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `attempts` keeps counting every provider call for the audit trail. A manual
 * retry of a failed notification grants a fresh retry budget by moving this
 * marker to the current attempt count: the retry policy only looks at
 * `attempts - retry_budget_start`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table): void {
            $table->unsignedSmallInteger('retry_budget_start')->default(0)->after('attempts');
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table): void {
            $table->dropColumn('retry_budget_start');
        });
    }
};
