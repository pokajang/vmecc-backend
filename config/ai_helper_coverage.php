<?php

return [
    'version' => 1,

    /*
    |--------------------------------------------------------------------------
    | Module coverage
    |--------------------------------------------------------------------------
    |
    | Every ModuleCatalog key must occur exactly once. The audit fails when a
    | module is added, removed, duplicated, or left unclassified.
    |
    */
    'modules' => [
        'deterministic_workflow' => [
            'settings.module_activation',
            'settings.role_permissions',
            'users',
            'teams',
            'roster',
            'leave.self_service',
            'overtime.self_service',
            'payroll.claims',
            'payroll.payslips',
            'reports',
            'reports.inspection',
            'reports.erco',
            'reports.drill',
            'reports.fitness_test',
        ],
        'grounded_guidance' => [
            'settings.system_maintenance',
            'settings.dashboard_visibility',
            'audit',
            'profile',
            'staff',
            'staff.directory',
            'teams.directory',
            'roster.shift_settings',
            'leave',
            'leave.management',
            'leave.assignments',
            'leave.holidays',
            'leave.workflow_rules',
            'overtime',
            'overtime.management',
            'overtime.workflow_rules',
            'overtime.rate_settings',
            'payroll',
            'payroll.self_service',
            'payroll.salary_claims_management',
            'payroll.salary_settings',
            'payroll.salary_assignments',
            'payroll.workflow_rules',
            'payroll.company_profile',
            'payroll.statutory_rates',
            'payroll.payment_actions',
            'workflow_notifications',
        ],
        'product_navigation' => [
            'dashboard',
            'dashboard.payroll',
            'dashboard.overtime',
            'dashboard.leave',
            'dashboard.roster',
            'dashboard.reports',
            'messages',
            'reports.pdf_exports',
        ],
        'clarification_required' => [],
        'intentionally_unsupported' => [
            // Shared infrastructure, not a standalone user-facing workflow.
            'workflow_attachments',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Representative intent corpus
    |--------------------------------------------------------------------------
    |
    | This is an executable breadth sample, not a hand-picked happy-path test.
    | `operations` are canonical user goals; a mismatch is a classified Phase 2
    | implementation gap and does not invalidate the Phase 1 inventory itself.
    |
    */
    'queries' => [
        ['id' => 'report.erco.write.ms', 'module' => 'reports.erco', 'message' => 'macam mana nak tulis report erco', 'topics' => ['report', 'erco'], 'operations' => ['create'], 'tasks' => ['reports.erco.manage']],
        ['id' => 'report.erco.edit.en', 'module' => 'reports.erco', 'message' => 'how can I revise an ERCO report', 'topics' => ['report', 'erco'], 'operations' => ['edit'], 'tasks' => ['reports.erco.manage']],
        ['id' => 'report.drill.submit.en', 'module' => 'reports.drill', 'message' => 'how do I submit a drill report', 'topics' => ['report', 'drill'], 'operations' => ['submit'], 'tasks' => ['reports.drill.manage']],
        ['id' => 'report.fitness.create.ms', 'module' => 'reports.fitness_test', 'message' => 'cara buat laporan ujian kecergasan', 'topics' => ['report', 'fitness'], 'operations' => ['create'], 'tasks' => ['reports.fitness.manage']],
        ['id' => 'report.manage.approve.en', 'module' => 'reports', 'message' => 'how do I review and approve a submitted report', 'topics' => ['report', 'report_management'], 'operations' => ['review', 'approve'], 'tasks' => ['reports.review']],
        ['id' => 'report.generic.create.en', 'module' => 'reports', 'message' => 'how do I create a report', 'topics' => ['report'], 'operations' => ['create'], 'tasks' => ['reports.navigate']],
        ['id' => 'report.erco.download.en', 'module' => 'reports.erco', 'message' => 'how do I download an ERCO report PDF', 'topics' => ['report', 'erco'], 'operations' => ['download'], 'tasks' => ['reports.erco.manage']],
        ['id' => 'report.drill.edit.ms', 'module' => 'reports.drill', 'message' => 'cara kemas kini laporan latihan', 'topics' => ['report', 'drill'], 'operations' => ['edit'], 'tasks' => ['reports.drill.manage']],
        ['id' => 'report.fitness.submit.en', 'module' => 'reports.fitness_test', 'message' => 'how do I submit a fitness report', 'topics' => ['report', 'fitness'], 'operations' => ['submit'], 'tasks' => ['reports.fitness.manage']],
        ['id' => 'report.erco.ambiguous-check.ms', 'module' => 'reports.erco', 'message' => 'cara semak report ERCO', 'topics' => ['report', 'erco'], 'operations' => ['view']],
        ['id' => 'inspection.hse.edit.en', 'module' => 'reports.inspection', 'message' => 'how can I write revise or edit an HSE inspection report', 'topics' => ['inspection', 'hse_inspection'], 'operations' => ['create', 'edit'], 'tasks' => ['inspection.conduct']],
        ['id' => 'inspection.general.submit.en', 'module' => 'reports.inspection', 'message' => 'how can I submit a general inspection', 'topics' => ['inspection'], 'operations' => ['submit'], 'tasks' => ['inspection.conduct']],
        ['id' => 'inspection.firetruck.conduct.ms', 'module' => 'reports.inspection', 'message' => 'cara jalankan pemeriksaan harian lori bomba', 'topics' => ['inspection', 'fire_truck'], 'operations' => ['inspect'], 'tasks' => ['inspection.conduct']],
        ['id' => 'inspection.extinguisher.conduct.en', 'module' => 'reports.inspection', 'message' => 'how do I conduct a fire extinguisher inspection', 'topics' => ['inspection', 'extinguisher'], 'operations' => ['inspect'], 'tasks' => ['inspection.conduct']],
        ['id' => 'inspection.scba.conduct.en', 'module' => 'reports.inspection', 'message' => 'how do I conduct an SCBA inspection', 'topics' => ['inspection', 'scba_inspection'], 'operations' => ['inspect'], 'tasks' => ['inspection.conduct']],
        ['id' => 'inspection.hydraulic.conduct.ms', 'module' => 'reports.inspection', 'message' => 'cara buat pemeriksaan alat hidraulik', 'topics' => ['inspection', 'hydraulic_rescue_inspection'], 'operations' => ['create', 'inspect'], 'tasks' => ['inspection.conduct']],
        ['id' => 'inspection.issue.manage.en', 'module' => 'reports.inspection', 'message' => 'how do I record an inspection defect', 'topics' => ['inspection', 'inspection_issue'], 'operations' => ['create'], 'tasks' => ['inspection.issue.manage']],
        ['id' => 'inspection.issue.verify.ms', 'module' => 'reports.inspection', 'message' => 'cara buat pengesahan isu pemeriksaan', 'topics' => ['inspection', 'inspection_issue', 'inspection_verification'], 'operations' => ['approve'], 'tasks' => ['inspection.issue.verify']],
        ['id' => 'inspection.height-rescue.en', 'module' => 'reports.inspection', 'message' => 'where can I find the procedure for rescue at height', 'topics' => ['height_rescue'], 'operations' => ['view']],
        ['id' => 'leave.apply.ms', 'module' => 'leave.self_service', 'message' => 'macam mana nak mohon cuti', 'topics' => ['leave'], 'operations' => ['create'], 'tasks' => ['leave.self_service']],
        ['id' => 'leave.cancel.en', 'module' => 'leave.self_service', 'message' => 'how can I cancel my leave request', 'topics' => ['leave'], 'operations' => ['cancel'], 'tasks' => ['leave.self_service']],
        ['id' => 'leave.withdraw.ms', 'module' => 'leave.self_service', 'message' => 'cara tarik balik permohonan cuti', 'topics' => ['leave'], 'operations' => ['cancel'], 'tasks' => ['leave.self_service']],
        ['id' => 'leave.entitlement.view.ms', 'module' => 'leave.assignments', 'message' => 'di mana saya boleh semak baki cuti', 'topics' => ['leave', 'leave_entitlement'], 'operations' => ['view'], 'tasks' => ['leave.self_service']],
        ['id' => 'leave.rules.configure.en', 'module' => 'leave.workflow_rules', 'message' => 'how do I configure leave workflow rules', 'topics' => ['leave', 'workflow_rule'], 'operations' => ['configure']],
        ['id' => 'overtime.apply.en', 'module' => 'overtime.self_service', 'message' => 'how do I apply for overtime', 'topics' => ['overtime'], 'operations' => ['create'], 'tasks' => ['overtime.self_service']],
        ['id' => 'overtime.rate.configure.ms', 'module' => 'overtime.rate_settings', 'message' => 'cara konfigurasi kadar kerja lebih masa', 'topics' => ['overtime', 'overtime_rate'], 'operations' => ['configure']],
        ['id' => 'payroll.payslip.view.ms', 'module' => 'payroll.payslips', 'message' => 'cara lihat dan muat turun slip gaji', 'topics' => ['payroll'], 'operations' => ['view'], 'tasks' => ['payroll.payslip.view']],
        ['id' => 'payroll.claim.submit.en', 'module' => 'payroll.claims', 'message' => 'how do I create and submit a salary claim', 'topics' => ['salary_claim'], 'operations' => ['create', 'submit'], 'tasks' => ['payroll.claim.submit']],
        ['id' => 'payroll.payment.en', 'module' => 'payroll.payment_actions', 'message' => 'how do I mark an approved salary claim as paid', 'topics' => ['salary_claim', 'payment'], 'operations' => ['approve', 'pay'], 'tasks' => ['payroll.payment.manage']],
        ['id' => 'payroll.payment.reverse.en', 'module' => 'payroll.payment_actions', 'message' => 'how do I unmark a paid salary claim', 'topics' => ['salary_claim', 'payment'], 'operations' => ['pay'], 'tasks' => ['payroll.payment.manage']],
        ['id' => 'payroll.assignment.ms', 'module' => 'payroll.salary_assignments', 'message' => 'cara tetapkan gaji pekerja', 'topics' => ['salary_assignment', 'staff'], 'operations' => ['configure']],
        ['id' => 'payroll.statutory.en', 'module' => 'payroll.statutory_rates', 'message' => 'how do I configure statutory rates for EPF and SOCSO', 'topics' => ['statutory_rate'], 'operations' => ['configure']],
        ['id' => 'payroll.company-profile.ms', 'module' => 'payroll.company_profile', 'message' => 'cara kemas kini profil syarikat untuk payroll', 'topics' => ['payroll', 'company_profile'], 'operations' => ['edit']],
        ['id' => 'roster.publish.en', 'module' => 'roster', 'message' => 'how do I create and publish a duty roster', 'topics' => ['roster'], 'operations' => ['create'], 'tasks' => ['roster.manage']],
        ['id' => 'teams.manage.ms', 'module' => 'teams', 'message' => 'cara tambah pekerja ke dalam pasukan', 'topics' => ['team', 'staff'], 'operations' => ['create'], 'tasks' => ['teams.manage']],
        ['id' => 'users.manage.en', 'module' => 'users', 'message' => 'how do I create and activate a user account', 'topics' => ['user_administration'], 'operations' => ['create'], 'tasks' => ['users.manage']],
        ['id' => 'roles.configure.ms', 'module' => 'settings.role_permissions', 'message' => 'cara ubah dan simpan kebenaran akses peranan', 'topics' => ['role_permission'], 'operations' => ['configure'], 'tasks' => ['roles.permissions.manage']],
        ['id' => 'module.activation.en', 'module' => 'settings.module_activation', 'message' => 'how do I enable a module', 'topics' => ['module_activation'], 'operations' => ['configure'], 'tasks' => ['settings.module_activation']],
        ['id' => 'settings.dashboard-visibility.en', 'module' => 'settings.dashboard_visibility', 'message' => 'how do I configure dashboard visibility', 'topics' => ['dashboard', 'dashboard_visibility'], 'operations' => ['configure']],
        ['id' => 'settings.system-maintenance.ms', 'module' => 'settings.system_maintenance', 'message' => 'cara aktifkan mod penyelenggaraan sistem', 'topics' => ['system_maintenance'], 'operations' => ['configure']],
        ['id' => 'settings.inspection-workflow.en', 'module' => 'reports.inspection', 'message' => 'how do I configure inspection workflow settings', 'topics' => ['inspection', 'workflow_setting'], 'operations' => ['configure'], 'tasks' => ['inspection.workflow.configure']],
        ['id' => 'profile.security.ms', 'module' => 'profile', 'message' => 'cara tukar kata laluan saya', 'topics' => ['password_security'], 'operations' => ['edit']],
        ['id' => 'profile.overview.en', 'module' => 'profile', 'message' => 'where can I view my personal profile', 'topics' => ['profile'], 'operations' => ['view']],
        ['id' => 'profile.banking.ms', 'module' => 'profile', 'message' => 'cara kemas kini maklumat akaun bank saya', 'topics' => ['banking'], 'operations' => ['edit']],
        ['id' => 'profile.banking.mixed', 'module' => 'profile', 'message' => 'how nak update akaun bank saya', 'topics' => ['banking'], 'operations' => ['edit']],
        ['id' => 'profile.medical.en', 'module' => 'profile', 'message' => 'where can I view my medical profile', 'topics' => ['medical'], 'operations' => ['view']],
        ['id' => 'profile.emergency.ms', 'module' => 'profile', 'message' => 'cara kemas kini waris kecemasan', 'topics' => ['emergency_contact'], 'operations' => ['edit']],
        ['id' => 'messages.view.en', 'module' => 'messages', 'message' => 'where can I find my messages', 'topics' => ['message'], 'operations' => ['view']],
        ['id' => 'leave.holiday.en', 'module' => 'leave.holidays', 'message' => 'where can I view the public holiday list', 'topics' => ['holiday'], 'operations' => ['view']],
        ['id' => 'notifications.configure.ms', 'module' => 'workflow_notifications', 'message' => 'cara konfigurasi tetapan pemberitahuan', 'topics' => ['notification'], 'operations' => ['configure']],
        ['id' => 'dashboard.view.ms', 'module' => 'dashboard', 'message' => 'apa yang boleh saya lihat di papan pemuka', 'topics' => ['dashboard'], 'operations' => ['view']],
        ['id' => 'audit.view.en', 'module' => 'audit', 'message' => 'where can I view the audit logs', 'topics' => ['audit_log'], 'operations' => ['view']],
        ['id' => 'ask-ai.usage.en', 'module' => 'profile', 'message' => 'how do I use Ask AI', 'topics' => ['ask_ai']],
        ['id' => 'system.overview.ms', 'module' => 'dashboard', 'message' => 'apa fungsi sistem VMECC dan modul yang ada', 'topics' => ['system_overview']],
    ],
];
