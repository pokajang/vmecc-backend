<?php

namespace Tests\Feature;

use Tests\TestCase;

class PdfSharedVisualComponentsTest extends TestCase
{
    public function test_status_badge_uses_semantic_tones_without_trusting_status_markup(): void
    {
        foreach ([
            'Approved' => 'approved',
            'Completed' => 'completed',
            'Submitted' => 'submitted',
            'Resubmitted' => 'submitted',
            'Reviewed' => 'reviewed',
            'Checked' => 'checked',
            'Rejected' => 'rejected',
            'Draft' => 'draft',
            'Unexpected' => 'neutral',
        ] as $status => $tone) {
            $html = view('pdf.shared.status-badge', ['status' => $status])->render();

            $this->assertStringContainsString('status-badge--'.$tone, $html);
            $this->assertStringContainsString('>'.$status.'</span>', $html);
        }

        $html = view('pdf.shared.status-badge', ['status' => '<script>alert(1)</script>'])->render();
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }
}
