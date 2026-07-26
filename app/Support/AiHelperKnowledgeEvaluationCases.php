<?php

namespace App\Support;

use App\Models\AiHelperKnowledgeEntry;

class AiHelperKnowledgeEvaluationCases
{
    /** @return array<int, array<string, mixed>> */
    public static function all(): array
    {
        return array_merge(self::core(), self::corpusCoverage());
    }

    /** @return array<int, array<string, mixed>> */
    public static function core(): array
    {
        return [
            [
                'id' => 'catalogue',
                'question' => 'List all annexes and knowledge documents.',
                'catalogue_total' => 35,
                'answer_tokens' => ['35 active AI knowledge documents', 'ANNEX 18 ERP for Man Overboard (MOB)', 'Rev 001 - Feb 2026'],
            ],
            [
                'id' => 'emergency_number',
                'question' => 'What is 999 according to Annex 1?',
                'titles' => ['ANNEX 1 Terminologies and Definitions'],
                'evidence_tokens' => ['999', 'official Malaysian Emergency Service Centre telephone number'],
                'answer_tokens' => ['999', 'Malaysian Emergency Service Centre'],
            ],
            [
                'id' => 'multiple_casualties',
                'question' => 'According to Annex 11, what should happen for multiple casualties, what ambulance number is specified, and what is the team capacity?',
                'titles' => ['ANNEX 11 Epidemic ERP'],
                'evidence_tokens' => ['mutual aid support', 'maximum of 2 casualties', '999 for ambulance'],
                'answer_tokens' => ['mutual aid', 'maximum of 2 casualties', '999'],
            ],
            [
                'id' => 'incident_controller',
                'question' => 'What are the Incident Controller incident duties in Annex 2?',
                'titles' => ['ANNEX 2 Roles, Responsibilities and Authorities'],
                'evidence_tokens' => ['INCIDENT CONTROLLER (IC)', 'Utilize the "TIME OUT" process', 'Deliver initial incident briefing'],
                'answer_tokens' => ['TIME OUT', 'initial incident briefing'],
            ],
            [
                'id' => 'document_code',
                'question' => 'In VMECC-OPR-SOP-003, how is 999 defined?',
                'titles' => ['RESPONSE ON EMERGENCY PROCEDURE VMECC-OPR-SOP-003'],
                'evidence_tokens' => ['999', 'official Malaysian Emergency Service Centre telephone number'],
                'answer_tokens' => ['999', 'Malaysian Emergency Service Centre'],
            ],
            [
                'id' => 'multiple_revisions',
                'question' => 'Which Annex 18 revisions are available?',
                'titles' => [
                    'ANNEX 18 ERP for Man Overboard (MOB)',
                    'ANNEX 18 ERP for Man Overboard (MOB).Rev 001 - Feb 2026',
                ],
                'evidence_tokens' => ['Man Overboard'],
                'answer_tokens' => ['Annex 18', 'Rev 001', 'revision not stated'],
            ],
            [
                'id' => 'follow_up',
                'question' => 'What rescue turns does it specify?',
                'previous_user_messages' => ['Explain the Man Overboard procedure in Annex 18.'],
                'titles' => ['ANNEX 18 ERP for Man Overboard (MOB)'],
                'evidence_tokens' => ['Destroyer Turn', 'Anderson Turn', 'Williamson Turn'],
                'answer_tokens' => ['Destroyer Turn', 'Anderson Turn', 'Williamson Turn'],
                'answer_forbidden_patterns' => ['Rev 001', 'Rev.001', 'Feb 2026'],
            ],
            [
                'id' => 'source_visual',
                'question' => 'According to Annex 25, what areas are shown in assembly zones 4B and 6, and is the source layout available?',
                'titles' => ['ANNEX 25 Assembly Area Layout'],
                'evidence_tokens' => ['Lab and substation area', 'Contractor Villa and Waste Management Area'],
                'answer_tokens' => ['Lab and substation area', 'Contractor Villa and Waste Management Area'],
                'visual_reference' => true,
            ],
            [
                'id' => 'not_found',
                'question' => 'What is the Wi-Fi password for the VMECC control room?',
                'expect_no_guidance' => true,
                'answer_patterns' => [
                    'not found',
                    'not available',
                    'does not contain',
                    'could not find',
                    'does not have access',
                    'cannot provide',
                    "can't provide",
                    'can’t provide',
                ],
            ],
            [
                'id' => 'bahasa_melayu',
                'question' => 'Apakah maksud 999 menurut Lampiran 1?',
                'response_language' => 'bm',
                'exact_document_titles' => ['ANNEX 1 Terminologies and Definitions'],
                'document_title_count' => 1,
                'evidence_tokens' => ['999', 'official Malaysian Emergency Service Centre telephone number'],
                'answer_tokens' => ['999', 'kecemasan'],
            ],
            [
                'id' => 'mixed_language',
                'question' => 'Untuk multiple casualties in Annex 11, berapa maximum capacity, siapa perlu call mutual aid, dan nombor apa untuk ambulance?',
                'response_language' => 'auto',
                'exact_document_titles' => ['ANNEX 11 Epidemic ERP'],
                'document_title_count' => 1,
                'evidence_tokens' => ['maximum of 2 casualties', 'IC shall call for mutual aid support', '999 for ambulance'],
                'answer_tokens' => ['2 casualties', 'IC', '999'],
            ],
            [
                'id' => 'specific_revision',
                'question' => 'Use Annex 18 Rev 001 only. What criteria trigger transition from Primary Search to Secondary Search?',
                'response_language' => 'en',
                'exact_document_titles' => ['ANNEX 18 ERP for Man Overboard (MOB).Rev 001 - Feb 2026'],
                'document_title_count' => 1,
                'evidence_tokens' => ['Transition Criteria (Primary to Secondary Search)', 'Victim is not located during Primary Search', 'Visual contact is lost'],
                'answer_tokens' => ['Rev 001', 'Victim is not located', 'Visual contact is lost'],
            ],
            [
                'id' => 'revision_conflict',
                'question' => 'Compare both available Annex 18 sources. Does the knowledge establish which source is authoritative? Do not assume.',
                'response_language' => 'en',
                'exact_document_titles' => [
                    'ANNEX 18 ERP for Man Overboard (MOB)',
                    'ANNEX 18 ERP for Man Overboard (MOB).Rev 001 - Feb 2026',
                ],
                'document_title_count' => 2,
                'evidence_tokens' => ['Man Overboard'],
                'answer_tokens' => ['Rev 001'],
                'answer_any_tokens' => [[
                    'revision not stated',
                    'no revision stated',
                    'revision is not stated',
                ]],
                'answer_patterns' => ['does not establish', 'cannot determine', 'does not identify', 'not enough information'],
                'answer_forbidden_patterns' => ['Rev 001 is authoritative', 'Rev 001 is the authoritative'],
            ],
            [
                'id' => 'cross_document',
                'question' => 'Compare the multiple-casualty mutual-aid instructions in Annex 10 and Annex 11.',
                'response_language' => 'en',
                'exact_document_titles' => ['ANNEX 10 ERP Body Injury', 'ANNEX 11 Epidemic ERP'],
                'document_title_count' => 2,
                'evidence_tokens' => ['maximum of 2 casualties', '999 for ambulance'],
                'answer_tokens' => ['Annex 10', 'Annex 11', 'maximum of 2 casualties', '999'],
            ],
            [
                'id' => 'sow_er_site_access_and_coverage',
                'question' => 'How can the VMM site be accessed and what area does the emergency response service cover?',
                'response_language' => 'en',
                'titles' => ['SOW ER Service 2023-2024 - Sanitized Operational Edition'],
                'expected_topic_key' => 'emergency_response_service',
                'expected_source_type' => AiHelperKnowledgeEntry::KNOWLEDGE_REFERENCE_DOCUMENT,
                'evidence_tokens' => ['1196 acres', 'Teluk Rubiah', 'Jalan Semarak Api', 'permanent or temporarily determined under VMM control'],
                'answer_tokens' => ['1196 acres', 'Teluk Rubiah', 'Jalan Semarak Api'],
            ],
            [
                'id' => 'sow_er_trt_staffing',
                'question' => 'For the emergency response service, what are the required manpower positions and quantities per shift?',
                'response_language' => 'en',
                'titles' => ['SOW ER Service 2023-2024 - Sanitized Operational Edition'],
                'expected_topic_key' => 'emergency_response_service',
                'expected_source_type' => AiHelperKnowledgeEntry::KNOWLEDGE_REFERENCE_DOCUMENT,
                'evidence_tokens' => ['Tactical Response Team Member', '4', '16', '30', '24/7'],
                'answer_tokens' => ['Tactical Response Team Member', '16', '24/7'],
            ],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public static function corpusCoverage(): array
    {
        $titles = [
            'ANNEX 1 Terminologies and Definitions',
            'ANNEX 2 Roles, Responsibilities and Authorities',
            'ANNEX 3 VMM Area Layout',
            'ANNEX 4 Potential Emergency Scenario by Zone',
            'ANNEX 5 Detail of Emergency Response Management (ERM)',
            'ANNEX 6 Detail of Emergency Response Program (ERP)',
            'ANNEX 7 Legal and Other Requirement (LOR) List',
            'ANNEX 8 Drill Program',
            'ANNEX 9 General ERP PROCESS FLOWCHART',
            'ANNEX 10 ERP Body Injury',
            'ANNEX 11 Epidemic ERP',
            'ANNEX 12 Foodborne ERP',
            'ANNEX 13 Fire ERP',
            'ANNEX 14 ERP Fall from Height',
            'ANNEX 15 ERP for Confined Space',
            'ANNEX 16 ERP For Yard Port Machines, Structure Collapse',
            'ANNEX 17 ERP for Vehicle Accident',
            'ANNEX 18 ERP for Man Overboard (MOB)',
            'ANNEX 18 ERP for Man Overboard (MOB).Rev 001 - Feb 2026',
            'ANNEX 19 ERP For Breach of Hull_Vessel Sinking',
            'ANNEX 20 ERP For Radiation Leak',
            'ANNEX 21 ERP for Landslide_Hillside Collapse',
            'ANNEX 22 ERP for Pond Rupture',
            'ANNEX 23 ERP for Oil or Hazardous Chemical Spill',
            'ANNEX 24 ERP for Wildlife Attack',
            'ANNEX 25 Assembly Area Layout',
            'ANNEX 26 HSE Incident Communication Reporting Flow',
            'ANNEX 27 Mutual Aid Plan',
            'ANNEX 28 IC Time Out checklist',
            'ANNEX 29 Emergency Reporting Format',
            'COMMUNICATION PROCEDURE VMECC-OPR-SOP-001. Rev.001 (Approved)',
            'EMERGENCY ZONE PROCEDURE VMECC-OPR-SOP-002 (Approved)',
            'PRO-040582 - Emergency Response Plan - Vale Malaysia Minerals - Rev. 00',
            'RESPONSE ON EMERGENCY PROCEDURE VMECC-OPR-SOP-003. Rev.001 (Approved)',
            'SOW ER Service 2023-2024 - Sanitized Operational Edition',
        ];
        $templates = [
            'scope' => 'What scope and subject are covered by %s?',
            'procedure' => 'Explain the operational content in %s.',
            'requirements' => 'Which requirements are stated in %s?',
            'bahasa' => 'Apakah kandungan yang dinyatakan dalam %s?',
        ];

        return collect($titles)->flatMap(function (string $title) use ($templates) {
            $slug = trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($title)), '_');

            return collect($templates)->map(fn (string $template, string $variant) => [
                'id' => 'coverage_'.$slug.'_'.$variant,
                'suite' => 'coverage',
                'question' => sprintf($template, $title),
                'titles' => [$title],
                'retrieval_only' => true,
            ])->values();
        })->values()->all();
    }
}
