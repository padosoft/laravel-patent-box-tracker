<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Padosoft\PatentBoxTracker\Models\TrackedDossier;
use Padosoft\PatentBoxTracker\Models\TrackingSession;
use Padosoft\PatentBoxTracker\Renderers\RenderedDossier;

final class RenderTrackingSessionDossierJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly int $sessionId,
        public readonly string $format,
        public readonly string $locale,
    ) {}

    public function handle(): void
    {
        $session = TrackingSession::query()->find($this->sessionId);
        if ($session === null) {
            return;
        }
        $session->status = TrackingSession::STATUS_RUNNING;
        $session->save();

        try {
            $builder = $session->renderDossier()->locale($this->locale);
            $rendered = $this->format === 'json'
                ? $builder->toJson()
                : $builder->toPdf();
            $outPath = $this->resolveOutputPath();
            $this->persistRenderedDossier($rendered, $outPath);
        } catch (\Throwable $exception) {
            $session->status = TrackingSession::STATUS_FAILED;
            $session->save();
            throw $exception;
        }

        TrackedDossier::query()->create([
            'tracking_session_id' => $session->id,
            'format' => $this->format,
            'locale' => $this->locale,
            'path' => $outPath,
            'byte_size' => $rendered->byteSize,
            'sha256' => $rendered->sha256,
            'generated_at' => now(),
        ]);

        $session->status = TrackingSession::STATUS_RENDERED;
        $session->save();
    }

    private function resolveOutputPath(): string
    {
        $base = function_exists('storage_path')
            ? storage_path('dossiers')
            : sys_get_temp_dir().DIRECTORY_SEPARATOR.'patent-box-dossiers';

        $stamp = now()->setTimezone('UTC')->format('YmdHisv');
        $random = bin2hex(random_bytes(4));

        return $base.DIRECTORY_SEPARATOR.$this->sessionId.'-'.$this->locale.'-'.$stamp.'-'.$random.'.'.$this->format;
    }

    private function persistRenderedDossier(RenderedDossier $rendered, string $outPath): void
    {
        $rendered->save($outPath);
    }
}
