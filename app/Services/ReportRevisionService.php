<?php

namespace App\Services;

use App\Models\Report;
use App\Models\ReportRevision;

final class ReportRevisionService
{
    public function snapshot(Report $report, array $payload): ReportRevision
    {
        $revision = (int) $report->revision;
        $schemaVersion = $this->schemaVersionFromPayload($payload);
        $payloadChecksum = $this->checksum($payload);

        $snapshot = ReportRevision::query()->updateOrCreate(
            ['report_id' => (int) $report->id, 'revision' => $revision],
            [
                'schema_version' => $schemaVersion,
                'payload' => $payload,
                'payload_checksum' => $payloadChecksum,
                'created_by' => $report->owner_user_id,
            ],
        );
        $report->forceFill([
            'domain_projection_version' => $revision,
            'domain_projection_status' => 'projected',
            'domain_projected_at' => now(),
        ])->saveQuietly();

        return $snapshot;
    }

    private function schemaVersionFromPayload(array $payload): int
    {
        $value = $payload['schemaVersion'] ?? null;
        if (is_int($value) || ctype_digit((string) $value)) {
            return (int) $value;
        }

        return 1;
    }

    private function checksum(array $payload): string
    {
        $serializedPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($serializedPayload === false) {
            $serializedPayload = serialize($payload);
        }

        return hash('sha256', $serializedPayload);
    }
}
