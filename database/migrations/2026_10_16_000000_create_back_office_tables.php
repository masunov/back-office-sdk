<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The outbox of requests to back-office and the events received from it (back-office ROADMAP.md, 2.7).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'back_office_outbox',
            function (Blueprint $table) {
                $table->uuid('event_id')->primary();
                $table->string('stream')->nullable();
                $table->json('request');
                $table->string('status', 16);
                $table->unsignedInteger('attempts')->default(0);
                $table->timestamp('next_attempt_at')->nullable();
                $table->unsignedSmallInteger('last_status')->nullable();
                $table->text('last_error')->nullable();
                $table->json('response')->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->timestamp('failed_at')->nullable();
                $table->timestamps();

                $table->index(['status', 'next_attempt_at']);
                $table->index(['stream', 'id']);
            }
        );

        Schema::create(
            'back_office_webhook_events',
            function (Blueprint $table) {
                $table->uuid('event_id')->primary();
                $table->string('type');
                $table->timestamp('received_at');
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('back_office_webhook_events');
        Schema::dropIfExists('back_office_outbox');
    }
};
