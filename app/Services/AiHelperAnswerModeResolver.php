<?php

namespace App\Services;

final class AiHelperAnswerModeResolver
{
    /**
     * These subjects require approved operational evidence even when the user
     * does not explicitly name a guide or procedure.
     *
     * @var array<int, string>
     */
    private const ALWAYS_GROUNDED_TOPICS = [
        'inspection',
        'inspection_issue',
        'inspection_verification',
        'extinguisher',
        'fire_truck',
        'hse_inspection',
        'scba_inspection',
        'hydraulic_rescue_inspection',
        'height_rescue',
        'erco',
        'drill',
        'fitness',
        'system_maintenance',
        'leave_entitlement',
        'overtime_rate',
        'statutory_rate',
    ];

    /**
     * @param  array<int, string>  $taskKeys
     * @param  array<int, string>  $topicKeys
     * @param  array<int, string>  $operationKeys
     */
    public function resolve(
        string $intent,
        array $taskKeys,
        array $topicKeys,
        array $operationKeys,
        string $sourceMode,
        string $message,
    ): string {
        if ($intent === 'casual') {
            return 'general_conversation';
        }
        if ($intent === 'capability_catalogue' || in_array('system_overview', $topicKeys, true)) {
            return 'product_capability';
        }
        if ($intent === 'general_help') {
            return 'product_navigation';
        }
        if (in_array('dashboard', $topicKeys, true)
            && preg_match('/\b(?:what does|what is|show|shows|papar|dipaparkan|apa (?:yang )?ada)\b/u', $message) === 1) {
            return 'product_navigation';
        }
        if ($taskKeys !== []) {
            return 'product_workflow';
        }
        if ($this->requiresAuthoritativeEvidence($topicKeys, $operationKeys, $sourceMode, $message)) {
            return 'operational_knowledge';
        }

        return 'general_conversation';
    }

    public function evidenceRequired(string $answerMode): bool
    {
        return ! in_array($answerMode, ['general_conversation', 'sensitive'], true);
    }

    /**
     * Prefer natural conversation by default. Switch to grounded answering
     * only when the request positively identifies VMECC, documented policy,
     * a product action, or an operationally sensitive subject.
     *
     * @param  array<int, string>  $topicKeys
     * @param  array<int, string>  $operationKeys
     */
    private function requiresAuthoritativeEvidence(
        array $topicKeys,
        array $operationKeys,
        string $sourceMode,
        string $message,
    ): bool {
        if (array_intersect($topicKeys, self::ALWAYS_GROUNDED_TOPICS) !== []) {
            return true;
        }

        if ($sourceMode === 'reference' || $sourceMode === 'mixed') {
            return true;
        }

        $namesVmeccContext = preg_match(
            '/\b(?:vmecc|ask ai|system guide|panduan sistem|dashboard|page|screen|form|button|field|'
            .'halaman|paparan|borang|butang|medan|module|modul|workflow|aliran kerja)\b/u',
            $message,
        ) === 1;
        if ($namesVmeccContext) {
            return true;
        }

        if ($topicKeys !== [] && preg_match('/\b(?:menu|record|rekod)\b/u', $message) === 1) {
            return true;
        }

        if ($topicKeys !== [] && preg_match('/\b(?:entitlement|kelayakan|payslip|pay slip|slip gaji)\b/u', $message) === 1) {
            return true;
        }

        $namesDocument = preg_match(
            '/\b(?:annex(?:e)?|lampiran|revision|rev(?:ision)?|document|dokumen|guide|panduan)\b|'
            .'\b(?:[a-z]{2,}(?:-[a-z0-9]+){2,}|pro-\d{4,})\b/u',
            $message,
        ) === 1;
        if ($namesDocument) {
            return true;
        }

        return $topicKeys !== []
            && ($operationKeys !== [] || $sourceMode === 'system');
    }
}
