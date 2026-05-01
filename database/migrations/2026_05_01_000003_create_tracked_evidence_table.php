<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = config('patent-box-tracker.storage.connection');
        $schema = $connection ? Schema::connection($connection) : Schema::getFacadeRoot();

        $schema->create('tracked_evidence', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tracking_session_id')
                ->constrained('tracking_sessions')
                ->cascadeOnDelete();
            $table->string('kind', 20)->index();
            $table->string('path', 1024)->nullable();
            $table->string('slug', 200)->nullable()->index();
            $table->string('title', 500)->nullable();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_modified_at')->nullable();
            $table->integer('linked_commit_count')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        $connection = config('patent-box-tracker.storage.connection');
        $schema = $connection ? Schema::connection($connection) : Schema::getFacadeRoot();

        $schema->dropIfExists('tracked_evidence');
    }
};
