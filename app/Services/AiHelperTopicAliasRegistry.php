<?php

namespace App\Services;

use Illuminate\Support\Str;

class AiHelperTopicAliasRegistry
{
    /**
     * These aliases are retrieval metadata, not user-visible guide copy. Keep
     * them deliberately small and deterministic so Malay and mixed-language
     * questions do not require an additional model call just to find a topic.
     *
     * @var array<string, array<int, string>>
     */
    private const TOPICS = [
        'leave' => ['leave', 'cuti', 'mohon cuti', 'permohonan cuti', 'apply cuti', 'cuti tahunan'],
        'leave_entitlement' => ['leave entitlement', 'leave balance', 'remaining leave', 'kelayakan cuti', 'baki cuti', 'cuti berbaki'],
        'overtime' => ['overtime', 'over time', 'ot', 'kerja lebih masa', 'lebih masa'],
        'overtime_rate' => ['overtime rate', 'ot rate', 'overtime multiplier', 'kadar kerja lebih masa', 'kadar lebih masa', 'kadar ot'],
        'inspection' => ['inspection', 'inspect', 'inspection report', 'pemeriksaan', 'laporan pemeriksaan'],
        'inspection_issue' => ['inspection issue', 'inspection finding', 'defect', 'issue management', 'isu pemeriksaan', 'dapatan pemeriksaan', 'kecacatan'],
        'inspection_verification' => ['inspection verification', 'verify inspection issue', 'issue verification', 'pengesahan pemeriksaan', 'sahkan isu pemeriksaan', 'pengesahan isu'],
        'extinguisher' => ['fire extinguisher', 'fire extinguishers', 'extinguisher', 'extinguishers', 'alat pemadam api', 'pemadam api'],
        'height_rescue' => ['high-angle rescue', 'high angle rescue', 'rescue at height', 'work at height rescue', 'stuck at height', 'trapped at height', 'suspended person', 'menyelamat di tempat tinggi', 'mangsa di tempat tinggi', 'mangsa tersangkut', 'tersangkut di tempat tinggi'],
        'payroll' => ['payroll', 'payslip', 'pay slip', 'gaji', 'slip gaji'],
        'salary_claim' => ['salary claim', 'salary claims', 'tuntutan gaji', 'claim gaji'],
        'salary_assignment' => ['salary assignment', 'assign salary', 'tetapan gaji', 'gaji pekerja'],
        'payment' => ['payment', 'mark paid', 'paid claim', 'bayaran', 'tanda dibayar'],
        'statutory_rate' => ['statutory rate', 'statutory deduction', 'epf', 'kwsp', 'socso', 'perkeso', 'eis', 'sip', 'pcb', 'caruman berkanun', 'potongan berkanun', 'cukai gaji'],
        'company_profile' => ['company profile', 'employer details', 'company details', 'profil syarikat', 'maklumat syarikat', 'maklumat majikan'],
        'roster' => ['roster', 'duty roster', 'jadual tugas', 'jadual kerja'],
        'team' => ['team', 'teams', 'pasukan', 'kumpulan kerja'],
        'staff' => ['staff', 'employee', 'worker', 'kakitangan', 'pekerja'],
        'user_administration' => ['user administration', 'manage user', 'user account', 'pengguna', 'akaun pengguna'],
        'role_permission' => ['role permission', 'role permissions', 'assign role', 'access permission', 'peranan', 'kebenaran akses'],
        'password_security' => ['change password', 'reset password', 'security session', 'tukar kata laluan', 'set semula kata laluan'],
        'profile' => ['profile', 'personal profile', 'profil', 'maklumat peribadi'],
        'banking' => ['banking', 'bank account', 'bank details', 'akaun bank', 'maklumat bank'],
        'medical' => ['medical profile', 'medical details', 'maklumat perubatan', 'rekod perubatan'],
        'emergency_contact' => ['emergency contact', 'waris kecemasan', 'kontak kecemasan'],
        'message' => ['message', 'messages', 'inbox', 'mesej', 'peti masuk'],
        'report' => ['report', 'reports', 'laporan'],
        'report_management' => ['report management', 'manage reports', 'approve report', 'urus laporan', 'pengurusan laporan', 'luluskan laporan'],
        'erco' => ['erco', 'emergency response report', 'laporan tindak balas kecemasan'],
        'drill' => ['drill report', 'exercise report', 'laporan latihan', 'latihan kecemasan'],
        'fitness' => ['fitness report', 'fitness test', 'ujian kecergasan', 'laporan kecergasan'],
        'holiday' => ['holiday', 'public holiday', 'cuti umum', 'hari kelepasan'],
        'workflow_rule' => ['workflow rule', 'workflow rules', 'approval sequence', 'peraturan aliran kerja', 'urutan kelulusan'],
        'workflow_setting' => ['workflow setting', 'workflow settings', 'approval setting', 'tetapan aliran kerja', 'tetapan kelulusan'],
        'notification' => ['notification', 'notifications', 'pemberitahuan', 'notifikasi'],
        'dashboard' => ['dashboard', 'home page', 'papan pemuka', 'halaman utama'],
        'dashboard_visibility' => ['dashboard visibility', 'dashboard access', 'visible dashboard', 'keterlihatan papan pemuka', 'akses papan pemuka', 'paparan papan pemuka'],
        'module_activation' => ['module activation', 'activate module', 'enable module', 'disable module', 'pengaktifan modul', 'aktifkan modul', 'nyahaktif modul'],
        'system_maintenance' => ['system maintenance', 'maintenance mode', 'penyelenggaraan sistem'],
        'audit_log' => ['audit log', 'audit logs', 'activity log', 'log audit', 'rekod aktiviti'],
        'ask_ai' => ['ask ai', 'ai helper', 'pembantu ai'],
        'system_overview' => [
            'system',
            'system overview',
            'system scope',
            'application overview',
            'vmecc overview',
            'what can i do in vmecc',
            'what can i do in the system',
            'overall system',
            'system flow',
            'system modules',
            'system features',
            'vmecc features',
            'system guide',
            'application guide',
            'bagaimana guna sistem ini',
            'gambaran keseluruhan',
            'ciri-ciri sistem',
            'ciri sistem',
            'modul dalam sistem',
            'menu sistem',
        ],
    ];

    /** @return array<int, string> */
    public function topicKeys(string $value): array
    {
        $normalized = $this->normalize($value);

        return collect(self::TOPICS)
            ->filter(fn (array $aliases) => collect($aliases)->contains(
                fn (string $alias) => $this->containsPhrase($normalized, $this->normalize($alias)),
            ))
            ->keys()
            ->values()
            ->all();
    }

    /** @return array<int, string> */
    public function expandedTerms(array $topicKeys): array
    {
        return collect($topicKeys)
            ->flatMap(function (string $topic): array {
                $aliases = self::TOPICS[$topic] ?? [];

                return array_merge([$topic], $aliases);
            })
            ->flatMap(fn (string $alias) => preg_split('/[^\pL\pN]+/u', $this->normalize($alias)) ?: [])
            ->filter(fn (string $term) => Str::length($term) >= 2)
            ->unique()
            ->take(24)
            ->values()
            ->all();
    }

    public function matchScore(string $identity, array $topicKeys): int
    {
        $identity = $this->normalize($identity);

        return collect($topicKeys)->sum(function (string $topic) use ($identity): int {
            $aliases = array_merge([$topic], self::TOPICS[$topic] ?? []);
            $matched = collect($aliases)->filter(
                fn (string $alias) => $this->containsPhrase($identity, $this->normalize($alias)),
            )->count();

            return min(3, $matched);
        });
    }

    /** @return array<int, string> */
    public function matchedTopicKeys(string $identity, array $topicKeys): array
    {
        $identity = $this->normalize($identity);

        return collect($topicKeys)
            ->filter(function (string $topic) use ($identity): bool {
                $aliases = array_merge([$topic], self::TOPICS[$topic] ?? []);

                return collect($aliases)->contains(
                    fn (string $alias) => $this->containsPhrase($identity, $this->normalize($alias)),
                );
            })
            ->values()
            ->all();
    }

    private function normalize(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', Str::lower(str_replace(['_', '-'], ' ', $value))));
    }

    private function containsPhrase(string $haystack, string $phrase): bool
    {
        if ($phrase === '') {
            return false;
        }

        return preg_match('/(?<![\pL\pN])'.preg_quote($phrase, '/').'(?![\pL\pN])/u', $haystack) === 1;
    }
}
