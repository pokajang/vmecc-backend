<?php

namespace Tests\Fixtures;

final class DrillReferenceScenarios
{
    /**
     * @return array<string, array{0: array<string, mixed>, 1: array<int, string>}>
     */
    public static function cases(): array
    {
        return [
            'January body injury rescue' => [
                [
                    'schemaVersion' => 2,
                    'reportDate' => '2025-01-24',
                    'reportTime' => '20:24',
                    'reportIssuanceDate' => '2025-01-25',
                    'weather' => 'Cloudy',
                    'incidentType' => 'Body Injury Rescue Drill',
                    'exerciseCategories' => ['Rescue', 'Special Assistance'],
                    'location' => 'CT 09 Level 3 Sampling Area',
                    'exerciseTitle' => 'BV Lab staff fainted during sample collection',
                    'details' => 'A laboratory staff member fainted while collecting a sample at CT 09 Level 3.',
                    'exerciseObjectives' => [
                        ['text' => 'Validate casualty assessment and safe evacuation.'],
                    ],
                    'erpReferences' => [
                        ['annexNumber' => 'Annex 10', 'title' => 'ERP for Body Injury'],
                    ],
                    'respondingTeam' => [
                        'name' => 'Alpha Team',
                        'shift' => 'Night',
                        'attendance' => [
                            [
                                'name' => 'Sharman',
                                'role' => 'Assistant Station Commander',
                                'exerciseRole' => 'ASC',
                                'teamName' => 'VMECC',
                            ],
                        ],
                    ],
                    'summary' => 'The team assessed, secured, evacuated, and handed the simulated injured person to FAS.',
                    'chronology' => [
                        ['time' => '20:24', 'action' => 'CCR reported a fainted laboratory staff member.'],
                        ['time' => '20:54', 'action' => 'Exercise ended after medical handover.'],
                    ],
                    'postIncidentAnalysis' => [
                        'strengths' => ['Response time met the 2025 KPI target.'],
                        'resourcesMobilised' => ['Full SkedCo stretcher and splinting equipment.'],
                        'improvementOpportunities' => ['Improve responder and medic radio communication.'],
                    ],
                ],
                [
                    'Body Injury Rescue Drill',
                    'CT 09 Level 3 Sampling Area',
                    'BV Lab staff fainted during sample collection',
                    'Annex 10',
                    'ERP for Body Injury',
                    'Sharman',
                    'Full SkedCo stretcher and splinting equipment.',
                ],
            ],
            'April bus accident rescue' => [
                [
                    'schemaVersion' => 2,
                    'reportDate' => '2025-04-22',
                    'reportTime' => '21:15',
                    'reportIssuanceDate' => '2025-04-23',
                    'weather' => 'Clear',
                    'incidentType' => 'Bus Accident Rescue Drill',
                    'exerciseCategories' => ['Rescue', 'Hazmat / Oil Spill'],
                    'location' => 'CCR2',
                    'exerciseTitle' => 'Bus accident with two simulated casualties',
                    'details' => 'A bus accident involved two simulated casualties and a diesel spill near CCR2.',
                    'exerciseObjectives' => [
                        ['text' => 'Validate casualty extraction, spill control, and multi-agency coordination.'],
                    ],
                    'erpReferences' => [
                        ['annexNumber' => 'Annex 17', 'title' => 'ERP Vehicle Incident'],
                        ['annexNumber' => 'Annex 10', 'title' => 'ERP for Body Injury'],
                        ['annexNumber' => 'Annex 23', 'title' => 'ERP for Oil and Hazardous Chemical Spills'],
                    ],
                    'respondingTeam' => [
                        'name' => 'Bravo Team',
                        'shift' => 'Night',
                        'attendance' => [
                            ['name' => 'Ridzal Aziz', 'role' => 'Station Commander', 'exerciseRole' => 'SC', 'teamName' => 'VMECC'],
                            ['name' => 'Sofjowardi', 'role' => 'Assistant Station Commander', 'exerciseRole' => 'ASC', 'teamName' => 'VMECC'],
                            ['name' => 'Shah', 'role' => 'Responder', 'exerciseRole' => 'TRT1', 'teamName' => 'Bravo Team'],
                        ],
                    ],
                    'summary' => 'The team extracted both casualties, contained the simulated diesel spill, and completed a final bus assessment.',
                    'chronology' => [
                        ['time' => '21:15', 'action' => 'CCR reported the simulated bus accident.'],
                        ['time' => '21:45', 'action' => 'Exercise ended after final assessment.'],
                    ],
                    'postIncidentAnalysis' => [
                        'strengths' => ['Good collaboration between VMECC, Medic, and Security teams.'],
                        'resourcesMobilised' => ['Spinal board, splint, cervical collar, and spill kit.'],
                        'improvementOpportunities' => ['Improve incident information gathering and command communication.'],
                    ],
                ],
                [
                    'Bus Accident Rescue Drill',
                    'CCR2',
                    'Bus accident with two simulated casualties',
                    'Annex 17',
                    'Annex 23',
                    'Ridzal Aziz',
                    'Sofjowardi',
                    'Spinal board, splint, cervical collar, and spill kit.',
                ],
            ],
            'August major fire' => [
                [
                    'schemaVersion' => 2,
                    'reportDate' => '2025-08-27',
                    'reportTime' => '14:59',
                    'reportIssuanceDate' => '2025-08-28',
                    'weather' => 'Clear',
                    'incidentType' => 'Major Fire Drill',
                    'exerciseCategories' => ['Fire', 'Rescue'],
                    'location' => 'VMM CT09, Zone 4',
                    'exerciseTitle' => 'Major fire at CT09 with one simulated injured person',
                    'details' => 'A major fire was simulated at CT09 with one person trapped at Level 3.',
                    'exerciseObjectives' => [
                        ['text' => 'Validate search, rescue, firefighting, cooling, and formal handover.'],
                    ],
                    'erpReferences' => [
                        ['annexNumber' => 'Annex 8', 'title' => 'Drill Program'],
                        ['annexNumber' => 'Annex 10', 'title' => 'Body Injury'],
                        ['annexNumber' => 'Annex 13', 'title' => 'ERP Fire'],
                    ],
                    'respondingTeam' => [
                        'name' => 'Alpha Team',
                        'shift' => 'Day',
                        'attendance' => [
                            ['name' => 'Azrul Azian', 'role' => 'Station Commander', 'exerciseRole' => 'SC', 'teamName' => 'VMECC'],
                            ['name' => 'Rosli', 'role' => 'Fire Training Officer', 'exerciseRole' => 'ASC', 'teamName' => 'VMECC'],
                            ['name' => 'Ammar', 'role' => 'Responder', 'exerciseRole' => 'TRT1', 'teamName' => 'Alpha Team'],
                        ],
                    ],
                    'summary' => 'The team rescued the casualty, extinguished the fire, completed cooling and overhaul, and handed the area over.',
                    'chronology' => [
                        ['time' => '14:59', 'action' => 'CCR reported a major fire at CT09.'],
                        ['time' => '15:50', 'action' => 'The team completed housekeeping and returned to base.'],
                    ],
                    'postIncidentAnalysis' => [
                        'strengths' => ['VMECC TRT demonstrated trained firefighting and rescue techniques.'],
                        'resourcesMobilised' => ['FRT, rescue ropes, Full Sked stretcher, and spinal board.'],
                        'improvementOpportunities' => ['Improve SCBA communication and initial scene size-up.'],
                    ],
                ],
                [
                    'Major Fire Drill',
                    'VMM CT09, Zone 4',
                    'Major fire at CT09 with one simulated injured person',
                    'Annex 8',
                    'Annex 13',
                    'Azrul Azian',
                    'Rosli',
                    'FRT, rescue ropes, Full Sked stretcher, and spinal board.',
                ],
            ],
        ];
    }
}
