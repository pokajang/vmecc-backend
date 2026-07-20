<?php

namespace Tests\Unit;

use App\Services\AiHelperApiResponder;
use Illuminate\Http\Request;
use Tests\TestCase;

class AiHelperApiResponderTest extends TestCase
{
    public function test_request_ids_accept_safe_trace_values_and_replace_untrusted_labels(): void
    {
        $responder = new AiHelperApiResponder;
        $valid = Request::create('/api/ai-helper/context');
        $valid->headers->set('X-Request-Id', 'edge:request-123.trace');

        $this->assertSame('edge:request-123.trace', $responder->requestId($valid));

        $invalid = Request::create('/api/ai-helper/context');
        $invalid->headers->set('X-Request-Id', 'untrusted request label');
        $generated = $responder->requestId($invalid);

        $this->assertNotSame('untrusted request label', $generated);
        $this->assertMatchesRegularExpression(
            '/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i',
            $generated,
        );
        $this->assertSame($generated, $responder->requestId($invalid));
    }
}
