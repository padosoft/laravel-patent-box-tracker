<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Padosoft\PatentBoxTracker\Api\RunTrackingSessionAction;
use Padosoft\PatentBoxTracker\Models\TrackingSession;

final class RunTrackingSessionJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param  list<array{path:string,role:string}>  $repositories
     */
    public function __construct(
        public readonly int $sessionId,
        public readonly array $repositories,
        public readonly string $periodFrom,
        public readonly string $periodTo,
        public readonly string $driver,
        public readonly string $model,
    ) {}

    public function handle(RunTrackingSessionAction $action): void
    {
        $session = TrackingSession::query()->find($this->sessionId);
        if ($session === null) {
            return;
        }
        $session->status = TrackingSession::STATUS_RUNNING;
        $session->finished_at = null;
        $session->save();

        try {
            $action->run(
                $session,
                $this->repositories,
                new \DateTimeImmutable($this->periodFrom.'T00:00:00Z'),
                new \DateTimeImmutable($this->periodTo.'T00:00:00Z'),
                $this->driver,
                $this->model,
            );
        } catch (\Throwable $exception) {
            $session->status = TrackingSession::STATUS_FAILED;
            $session->finished_at = now()->toDateTimeString();
            $session->save();
            throw $exception;
        }
    }
}
