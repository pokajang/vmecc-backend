<?php

namespace App\Services;

use App\Models\InspectionExtinguisherOperation;
use App\Models\InspectionSession;
use Illuminate\Support\Facades\Log;

class InspectionExtinguisherOperationService
{
    /**
     * @return array{operation: InspectionExtinguisherOperation, replayed: bool, idReused: bool}
     */
    public function begin(
        string $operationUid,
        InspectionSession $session,
        string $assetKey,
        string $operationType,
        int $actorUserId,
        int $baseVersion,
        array $payload,
    ): array {
        $payloadHash = $this->payloadHash([
            'operationType' => $operationType,
            'baseVersion' => $baseVersion,
            'payload' => $payload,
        ]);
        $now = now();

        InspectionExtinguisherOperation::query()->insertOrIgnore([
            'operation_uid' => $operationUid,
            'inspection_session_id' => $session->id,
            'canonical_asset_key' => $assetKey,
            'operation_type' => $operationType,
            'actor_user_id' => $actorUserId,
            'base_version' => $baseVersion,
            'payload_hash' => $payloadHash,
            'status' => 'pending',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $operation = InspectionExtinguisherOperation::query()
            ->where('operation_uid', $operationUid)
            ->lockForUpdate()
            ->firstOrFail();
        $idReused =
            (int) $operation->inspection_session_id !== (int) $session->id
            || (string) $operation->canonical_asset_key !== $assetKey
            || (string) $operation->operation_type !== $operationType
            || (int) $operation->actor_user_id !== $actorUserId
            || (string) $operation->payload_hash !== $payloadHash;
        $replayed = ! $idReused && $operation->status !== 'pending';

        if ($idReused || $replayed) {
            $this->logOutcome($operation, $idReused ? 'operation_id_reused' : 'replayed');
        }

        return [
            'operation' => $operation,
            'replayed' => $replayed,
            'idReused' => $idReused,
        ];
    }

    public function succeed(
        InspectionExtinguisherOperation $operation,
        ?int $resultVersion,
        ?array $responsePayload,
    ): void {
        $operation->forceFill([
            'result_version' => $resultVersion,
            'status' => 'succeeded',
            'outcome_code' => 'inspection_operation_applied',
            'response_payload' => $responsePayload,
        ])->save();
        $this->logOutcome($operation, 'succeeded');
    }

    public function conflict(
        InspectionExtinguisherOperation $operation,
        string $outcomeCode,
        ?int $resultVersion,
        ?array $responsePayload,
    ): void {
        $operation->forceFill([
            'result_version' => $resultVersion,
            'status' => 'conflict',
            'outcome_code' => $outcomeCode,
            'response_payload' => $responsePayload,
        ])->save();
        $this->logOutcome($operation, $outcomeCode);
    }

    private function logOutcome(InspectionExtinguisherOperation $operation, string $outcome): void
    {
        Log::info('inspection_extinguisher_operation_outcome', [
            'operation_id' => $operation->operation_uid,
            'session_id' => $operation->inspection_session_id,
            'asset_key_hash' => hash('sha256', (string) $operation->canonical_asset_key),
            'user_id' => $operation->actor_user_id,
            'operation_type' => $operation->operation_type,
            'outcome' => $outcome,
            'duration_ms' => $operation->created_at
                ? max(0, (int) $operation->created_at->diffInMilliseconds(now()))
                : null,
        ]);
    }

    private function payloadHash(array $payload): string
    {
        $normalized = $this->sortRecursively($payload);

        return hash('sha256', json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function sortRecursively(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->sortRecursively($item), $value);
        }

        ksort($value);

        return array_map(fn (mixed $item): mixed => $this->sortRecursively($item), $value);
    }
}
