<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class LeavePolicyService
{
    private const TYPES = [
        'Annual Leave' => ['code' => 'AL', 'evidence' => false, 'coverage' => 'none'],
        'Medical Leave' => ['code' => 'ML', 'evidence' => true, 'coverage' => 'none'],
        'Emergency Leave' => ['code' => 'EL', 'evidence' => false, 'coverage' => 'none'],
        'Compassionate Leave' => ['code' => 'CL', 'evidence' => true, 'coverage' => 'none'],
        'Maternity Leave' => ['code' => 'MAT', 'evidence' => false, 'coverage' => 'none'],
        'Paternity Leave' => ['code' => 'PAT', 'evidence' => false, 'coverage' => 'none'],
        'Unpaid Leave' => ['code' => 'UL', 'evidence' => true, 'coverage' => 'multi-day'],
        'Other Leave' => ['code' => 'OL', 'evidence' => true, 'coverage' => 'always'],
    ];

    public function assertSupported(string $leaveType): void
    {
        if (! array_key_exists($leaveType, self::TYPES)) {
            throw ValidationException::withMessages([
                'leave_type' => ['The selected leave type is not supported.'],
            ]);
        }
    }

    public function codeFor(string $leaveType): string
    {
        $this->assertSupported($leaveType);

        return self::TYPES[$leaveType]['code'];
    }

    public function requiresEvidence(string $leaveType): bool
    {
        $this->assertSupported($leaveType);

        return self::TYPES[$leaveType]['evidence'];
    }

    public function requiresCoverage(string $leaveType, float $days): bool
    {
        $this->assertSupported($leaveType);

        return match (self::TYPES[$leaveType]['coverage']) {
            'always' => true,
            'multi-day' => $days > 1.0,
            default => false,
        };
    }

    public function types(): array
    {
        return self::TYPES;
    }
}
