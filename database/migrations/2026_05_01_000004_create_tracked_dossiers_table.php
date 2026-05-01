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

        $schema->create('tracked_dossiers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tracking_session_id')
                ->constrained('tracking_sessions')
                ->cascadeOnDelete();
            $table->string('format', 10)->index();
            $table->string('locale', 10)->default('it');
            $table->string('path', 1024)->nullable();
            $table->bigInteger('byte_size')->nullable();
            $table->string('sha256', 64)->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        $connection = config('patent-box-tracker.storage.connection');
        $schema = $connection ? Schema::connection($connection) : Schema::getFacadeRoot();

        $schema->dropIfExists('tracked_dossiers');
    }
};
