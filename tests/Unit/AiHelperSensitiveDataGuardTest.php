<?php

namespace Tests\Unit;

use App\Services\AiHelperSensitiveDataGuard;
use PHPUnit\Framework\TestCase;

class AiHelperSensitiveDataGuardTest extends TestCase
{
    public function test_blocks_pasted_values_but_allows_credential_workflow_questions(): void
    {
        $guard = new AiHelperSensitiveDataGuard;

        $this->assertSame([], $guard->categories('How do I change my password?'));
        $this->assertContains('credential_value', $guard->categories('password: VerySecret123'));
        $this->assertContains('identity_number', $guard->categories('My IC is 900101-14-5678'));
        $this->assertContains('bank_account', $guard->categories('Bank account number: 123456789012'));
        $this->assertContains('api_secret', $guard->categories('Use sk-abcdefghijklmnopqrstuvwxyz123456'));
    }
}
