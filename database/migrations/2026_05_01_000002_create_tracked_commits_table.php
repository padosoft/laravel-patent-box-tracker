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

        $schema->create('tracked_commits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tracking_session_id')
                ->constrained('tracking_sessions')
                ->cascadeOnDelete();
            $table->string('repository_path', 1024);
            $table->string('repository_role', 30)->nullable();
            $table->string('sha', 40)->index();
            $table->string('author_name', 200)->nullable();
            $table->string('author_email', 200)->nullable();
            $table->string('committer_email', 200)->nullable();
            $table->timestamp('committed_at')->nullable();
            $table->text('message')->nullable();
            $table->integer('files_changed_count')->nullable();
            $table->integer('insertions')->nullable();
            $table->integer('deletions')->nullable();
            $table->string('branch_name_canonical', 200)->nullable();
            $table->json('branch_semantics_json')->nullable();
            $table->string('ai_attribution', 20)->nullable();
            $table->string('phase', 20)->nullable()->index();
            $table->boolean('is_rd_qualified')->nullable()->index();
            $table->decimal('rd_qualification_confidence', 5, 4)->nullable();
            $table->text('rationale')->nullable();
            $table->string('rejected_phase', 20)->nullable();
            $table->json('evidence_used_json')->nullable();
            $table->string('hash_chain_prev', 64)->nullable();
            $table->string('hash_chain_self', 64)->nullable();
            $table->timestamps();

            $table->unique(
                ['tracking_session_id', 'repository_path', 'sha'],
                'uq_tracked_commits_session_repo_sha',
            );
        });
    }

    public function down(): void
    {
        $connection = config('patent-box-tracker.storage.connection');
        $schema = $connection ? Schema::connection($connection) : Schema::getFacadeRoot();

        $schema->dropIfExists('tracked_commits');
    }
};
