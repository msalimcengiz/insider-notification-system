<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('batch_id')->nullable()->constrained('notification_batches')->nullOnDelete();
            $table->foreignUuid('template_id')->nullable()->constrained('templates')->nullOnDelete();

            $table->string('channel', 16);
            $table->string('priority', 16);
            $table->string('recipient', 320);
            $table->string('subject')->nullable();
            $table->text('content');
            $table->jsonb('template_variables')->nullable();

            $table->string('status', 16);
            $table->string('idempotency_key')->nullable()->unique();
            $table->string('correlation_id', 64)->nullable();

            // Delivery bookkeeping. `attempts` counts real provider calls only:
            // a job released because of rate limiting does not consume it.
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('provider_message_id')->nullable();
            $table->text('last_error')->nullable();

            $table->timestampTz('scheduled_at')->nullable();
            $table->timestampTz('queued_at')->nullable();
            $table->timestampTz('processing_started_at')->nullable();
            $table->timestampTz('next_attempt_at')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampsTz();

            // Listing endpoint: filter by status / channel, newest first.
            $table->index(['status', 'channel', 'created_at']);
            $table->index('created_at');
            $table->index('batch_id');
        });

        // Valid values are enforced by the database too, not only by the app.
        DB::statement("ALTER TABLE notifications ADD CONSTRAINT notifications_channel_check CHECK (channel IN ('sms', 'email', 'push'))");
        DB::statement("ALTER TABLE notifications ADD CONSTRAINT notifications_priority_check CHECK (priority IN ('high', 'normal', 'low'))");
        DB::statement("ALTER TABLE notifications ADD CONSTRAINT notifications_status_check CHECK (status IN ('scheduled', 'pending', 'queued', 'processing', 'retrying', 'sent', 'failed', 'cancelled'))");

        // Small partial indexes for the scheduler and the recovery sweeper,
        // which only ever look at a handful of non-final rows.
        DB::statement("CREATE INDEX notifications_due_scheduled_index ON notifications (scheduled_at) WHERE status = 'scheduled'");
        DB::statement("CREATE INDEX notifications_in_flight_index ON notifications (status, updated_at) WHERE status IN ('pending', 'queued', 'processing', 'retrying')");
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
