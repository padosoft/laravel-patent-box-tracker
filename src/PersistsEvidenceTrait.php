<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker;

use Padosoft\PatentBoxTracker\Models\TrackedEvidence;
use Padosoft\PatentBoxTracker\Models\TrackingSession;
use Padosoft\PatentBoxTracker\Sources\EvidenceItem;

/**
 * Shared persistence helpers used by the console commands and the
 * fluent builder.  The two methods are byte-for-byte identical in
 * `TrackCommand`, `CrossRepoCommand`, and `PatentBoxTracker`; this
 * trait keeps them in one place so a schema change never has to be
 * applied three times.
 */
trait PersistsEvidenceTrait
{
    /**
     * Persist design-doc-link evidence items into `tracked_evidence`.
     *
     * @param  list<EvidenceItem>  $items
     */
    private function persistEvidence(TrackingSession $session, array $items): void
    {
        foreach ($items as $item) {
            if ($item->kind !== EvidenceItem::KIND_DESIGN_DOC_LINK) {
                continue;
            }

            $payload = $item->payload;
            $slug = is_string($payload['slug'] ?? null) ? (string) $payload['slug'] : null;
            $kind = is_string($payload['kind'] ?? null) ? (string) $payload['kind'] : 'plan';
            $path = is_string($payload['path'] ?? null) ? (string) $payload['path'] : null;
            $title = is_string($payload['title'] ?? null) ? (string) $payload['title'] : null;

            TrackedEvidence::query()->updateOrCreate(
                [
                    'tracking_session_id' => $session->id,
                    'kind' => $kind,
                    'slug' => $slug,
                    'path' => $path,
                ],
                [
                    'title' => $title,
                    'first_seen_at' => $payload['firstSeenAt'] ?? null,
                    'last_modified_at' => $payload['lastModifiedAt'] ?? null,
                    'linked_commit_count' => 0,
                ],
            );
        }
    }

    /**
     * Extract a non-empty string value from an evidence item payload.
     *
     * @param  array<string, mixed>  $payload
     */
    private function payloadString(array $payload, string $key): ?string
    {
        $value = $payload[$key] ?? null;
        if (is_string($value) && $value !== '') {
            return $value;
        }

        return null;
    }
}
