<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MessageAttachmentSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_message_attachments_support_the_models_soft_delete_contract(): void
    {
        $this->assertTrue(Schema::hasColumn('message_attachments', 'deleted_at'));
    }
}
