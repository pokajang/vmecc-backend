<?php

namespace App\Services;

use App\Models\InspectionDutyConfirmation;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InspectionDutyConfirmationService
{
    public function __construct(private readonly InspectionDutyContextResolver $contextResolver) {}

    public function issue(User $user, Request $request, array $input): array
    {
        $operation = strtolower(trim((string) $input['operation']));
        if (! in_array($operation, (array) config('inspection_duty.operations', []), true)) {
            $this->fail('inspection_operation_invalid', 'This inspection operation cannot be confirmed.', 422);
        }

        $context = $this->contextResolver->resolve($user);
        if (! hash_equals((string) $context['contextVersion'], (string) $input['contextVersion'])) {
            $this->fail('duty_context_changed', 'Duty context changed. Refresh before continuing.', 412, [
                'currentContext' => $context,
            ]);
        }

        $snapshot = $this->selectContext($context, $input);
        $formId = $this->nullableText($input['formId'] ?? null);
        $recordId = $this->nullableText($input['recordId'] ?? null);
        $idempotencyKey = $this->nullableText($input['idempotencyKey'] ?? null);
        $this->validateBinding($operation, $formId, $recordId, $idempotencyKey);
        $rawToken = Str::random(80);
        $ttl = max(1, min(30, (int) config('inspection_duty.confirmation_ttl_minutes', 10)));

        return DB::transaction(function () use ($user, $request, $input, $context, $snapshot, $operation, $formId, $recordId, $idempotencyKey, $rawToken, $ttl): array {
            $confirmation = InspectionDutyConfirmation::query()->create([
                'token_id' => (string) Str::uuid(),
                'token_hash' => hash('sha256', $rawToken),
                'user_id' => $user->id,
                'operation' => $operation,
                'context_version' => $context['contextVersion'],
                'source_version' => $context['sourceVersion'],
                'context_hash' => $this->bindingHash($snapshot, $operation, $formId, $recordId, $idempotencyKey),
                'context_snapshot' => $snapshot,
                'form_id' => $formId,
                'record_id' => $recordId,
                'idempotency_key' => $idempotencyKey,
                'request_id' => $this->nullableText($request->header('X-Request-ID')),
                'ip_address' => $request->ip(),
                'user_agent' => Str::limit((string) $request->userAgent(), 1000, ''),
                'reason' => $this->nullableText($input['reason'] ?? null),
                'expires_at' => now()->addMinutes($ttl),
            ]);

            AuditLogger::log($request, 'inspection_duty_confirmation_issued', null, [
                'token_id' => $confirmation->token_id,
                'operation' => $operation,
                'context_version' => $confirmation->context_version,
                'team_id' => $snapshot['teamId'] ?? null,
                'shift_key' => $snapshot['shiftKey'] ?? null,
                'record_id' => $confirmation->record_id,
            ]);

            return [
                'dutyConfirmationToken' => $rawToken,
                'tokenId' => $confirmation->token_id,
                'operation' => $operation,
                'expiresAt' => $confirmation->expires_at->toIso8601String(),
            ];
        });
    }

    public function consume(Request $request, string $operation, ?string $recordId = null, ?string $formId = null): ?array
    {
        if (! config('inspection_duty.enforcement_enabled', false)) {
            return $request->user() ? $this->contextResolver->resolve($request->user()) : null;
        }

        $rawToken = trim((string) $request->header('X-Duty-Confirmation', ''));
        if ($rawToken === '') {
            $this->fail('duty_confirmation_required', 'Confirm your current duty assignment before continuing.', 428);
        }

        $result = DB::transaction(function () use ($request, $operation, $recordId, $formId, $rawToken): array {
            $confirmation = InspectionDutyConfirmation::query()
                ->where('token_hash', hash('sha256', $rawToken))
                ->lockForUpdate()
                ->first();
            if (! $confirmation || (int) $confirmation->user_id !== (int) $request->user()?->id) {
                $this->fail('duty_confirmation_invalid', 'Duty confirmation is invalid.', 412);
            }
            if ($confirmation->revoked_at || $confirmation->consumed_at || $confirmation->expires_at->isPast()) {
                $code = $confirmation->expires_at->isPast() ? 'duty_confirmation_expired' : 'duty_confirmation_invalid';
                $this->fail($code, 'Duty confirmation expired or was already used.', 412);
            }
            if ($confirmation->operation !== $operation
                || ($confirmation->record_id !== null && $confirmation->record_id !== $recordId)
                || ($confirmation->form_id !== null && $confirmation->form_id !== $formId)
                || ($confirmation->idempotency_key !== null && $confirmation->idempotency_key !== $this->requestIdempotencyKey($request))) {
                $this->fail('duty_confirmation_invalid', 'Duty confirmation does not match this operation.', 412);
            }
            $expectedHash = $this->bindingHash(
                (array) $confirmation->context_snapshot,
                $confirmation->operation,
                $confirmation->form_id,
                $confirmation->record_id,
                $confirmation->idempotency_key,
            );
            if (! hash_equals($confirmation->context_hash, $expectedHash)) {
                $this->fail('duty_confirmation_invalid', 'Duty confirmation binding is invalid.', 412);
            }

            $current = $this->contextResolver->resolve($request->user());
            if (! hash_equals($confirmation->context_version, (string) $current['contextVersion'])) {
                $confirmation->update(['revoked_at' => now()]);

                return ['error' => [
                    'code' => 'duty_context_changed',
                    'message' => 'Duty context changed. Refresh before continuing.',
                    'status' => 412,
                    'currentContext' => $current,
                ]];
            }

            $confirmation->update(['consumed_at' => now()]);
            AuditLogger::log($request, 'inspection_duty_confirmation_consumed', null, [
                'token_id' => $confirmation->token_id,
                'operation' => $operation,
                'record_id' => $recordId,
            ]);

            return array_merge($confirmation->context_snapshot, [
                'confirmationTokenId' => $confirmation->token_id,
            ]);
        });

        if (isset($result['error'])) {
            $error = $result['error'];
            $this->fail(
                $error['code'],
                $error['message'],
                $error['status'],
                ['currentContext' => $error['currentContext']],
            );
        }

        return $result;
    }

    private function selectContext(array $context, array $input): array
    {
        if ($context['status'] === 'unmatched') {
            $this->fail('duty_context_unmatched', 'No active duty assignment is available.', 422);
        }
        if ($context['status'] === 'assigned') {
            return $context;
        }

        $teamId = (int) ($input['teamId'] ?? 0);
        $shiftKey = Str::slug((string) ($input['shiftKey'] ?? ''));
        $candidate = collect($context['candidates'])->first(
            fn (array $item) => (int) $item['teamId'] === $teamId && $item['shiftKey'] === $shiftKey
        );
        if (! $candidate) {
            $this->fail('duty_context_ambiguous', 'Select a valid team and shift before continuing.', 422, [
                'candidates' => $context['candidates'],
            ]);
        }

        return array_merge($context, $candidate, ['status' => 'assigned', 'confidence' => 'high', 'candidates' => []]);
    }

    private function bindingHash(
        array $snapshot,
        string $operation,
        ?string $formId,
        ?string $recordId,
        ?string $idempotencyKey,
    ): string {
        return hash('sha256', json_encode([
            'contextVersion' => $snapshot['contextVersion'],
            'teamId' => $snapshot['teamId'] ?? null,
            'shiftKey' => $snapshot['shiftKey'] ?? null,
            'operation' => $operation,
            'formId' => $formId,
            'recordId' => $recordId,
            'idempotencyKey' => $idempotencyKey,
        ], JSON_UNESCAPED_SLASHES));
    }

    private function validateBinding(
        string $operation,
        ?string $formId,
        ?string $recordId,
        ?string $idempotencyKey,
    ): void {
        if ($recordId === null) {
            $this->fail('duty_confirmation_binding_required', 'A record or session binding is required.', 422);
        }
        if (in_array($operation, ['submit', 'session-write', 'session-submit'], true) && $formId === null) {
            $this->fail('duty_confirmation_binding_required', 'A form binding is required.', 422);
        }
        if (in_array($operation, ['submit', 'session-write', 'session-submit'], true) && $idempotencyKey === null) {
            $this->fail('duty_confirmation_binding_required', 'An idempotency binding is required.', 422);
        }
    }

    private function requestIdempotencyKey(Request $request): ?string
    {
        foreach ([
            $request->header('Idempotency-Key'),
            $request->input('submission_key'),
            $request->input('operationId'),
            $request->input('operation_id'),
            $request->input('clientResultId'),
            $request->input('client_result_id'),
        ] as $candidate) {
            $value = $this->nullableText($candidate);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private function nullableText(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    private function fail(string $code, string $message, int $status, array $extra = []): never
    {
        throw new HttpResponseException(response()->json(array_merge([
            'message' => $message,
            'code' => $code,
        ], $extra), $status));
    }
}
