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

        $schema->create('tracking_sessions', function (Blueprint $table): void {
            $table->id();
            $table->json('tax_identity_json')->nullable();
            $table->timestamp('period_from')->nullable();
            $table->timestamp('period_to')->nullable();
            $table->json('cost_model_json')->nullable();
            $table->string('classifier_provider', 50)->nullable();
            $table->string('classifier_model', 100)->nullable();
            $table->bigInteger('classifier_seed')->nullable();
            $table->string('status', 20)->default('pending')->index();
            $table->decimal('cost_eur_actual', 10, 4)->nullable();
            $table->decimal('cost_eur_projected', 10, 4)->nullable();
            $table->decimal('golden_set_f1_score', 6, 4)->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        $connection = config('patent-box-tracker.storage.connection');
        $schema = $connection ? Schema::connection($connection) : Schema::getFacadeRoot();

        $schema->dropIfExists('tracking_sessions');
    }
};
