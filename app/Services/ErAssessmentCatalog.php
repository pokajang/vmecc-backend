<?php

namespace App\Services;

use App\Models\ErAssessmentType;

final class ErAssessmentCatalog
{
    public const SCHEMA_VERSION = 1;

    public const TEMPLATE_VERSION = 'VMECC-OPS-016-R0';

    public const DOCUMENT = [
        'title' => 'Emergency Response Assessment',
        'code' => 'VMECC-OPS-016',
        'revision' => '0',
    ];

    public const RESPONSE_OPTIONS = ['Yes', 'No', 'N/A'];

    private const TYPES = [
        'working-at-height' => [
            'label' => 'Working at Height',
            'worstCaseScenario' => 'Fall from height, fatality, suspension trauma, or injury caused by falling objects.',
            'requirements' => [
                ['id' => 'wah.scaffold-tagged', 'label' => 'Scaffold tagged & inspected (Green/Yellow/Red)'],
                ['id' => 'wah.fall-protection', 'label' => 'Fall protection system'],
                ['id' => 'wah.anchor-body-connector', 'label' => 'Anchor – Body - Connector'],
                ['id' => 'wah.tool-fall-protection', 'label' => 'Tool fall protection system (Lanyard / Netting / Toe board)'],
                ['id' => 'wah.escape-routes', 'label' => 'Escape Routes to AA'],
                ['id' => 'wah.exclusion-zone', 'label' => 'Barricade & exclusion zone established'],
            ],
        ],
        'confined-space' => [
            'label' => 'Confined Space / Enclosed Space Work',
            'worstCaseScenario' => 'Asphyxiation, toxic gas exposure, or multiple fatalities.',
            'requirements' => [
                ['id' => 'cs.permit-approved', 'label' => 'Confined Space Permit approved'],
                ['id' => 'cs.gas-testing', 'label' => 'Gas testing completed'],
                ['id' => 'cs.standby-person', 'label' => 'Standby person'],
                ['id' => 'cs.ventilation', 'label' => 'Ventilation in operation'],
                ['id' => 'cs.rescue-equipment', 'label' => 'Rescue equipment available'],
                ['id' => 'cs.escape-routes', 'label' => 'Escape Routes to AA'],
                ['id' => 'cs.exclusion-zone', 'label' => 'Barricade & exclusion zone established'],
            ],
        ],
        'hot-work' => [
            'label' => 'Hot Work Activities',
            'worstCaseScenario' => 'Fire, explosion, multiple injuries, or plant damage.',
            'requirements' => [
                ['id' => 'hw.fire-watch', 'label' => 'Fire watch assigned'],
                ['id' => 'hw.fire-extinguisher', 'label' => 'Fire extinguisher available'],
                ['id' => 'hw.flammables-controlled', 'label' => 'Flammable materials removed/protected'],
                ['id' => 'hw.fda-isolation', 'label' => 'FDA Isolation Approval'],
                ['id' => 'hw.escape-routes', 'label' => 'Escape Routes to AA'],
                ['id' => 'hw.exclusion-zone', 'label' => 'Barricade & exclusion zone established'],
            ],
        ],
        'lifting-operations' => [
            'label' => 'Lifting Operations',
            'worstCaseScenario' => 'Dropped load, crushing injury, or crane collapse.',
            'requirements' => [
                ['id' => 'lift.equipment-inspected', 'label' => 'Equipment inspected'],
                ['id' => 'lift.taglines', 'label' => 'Taglines used'],
                ['id' => 'lift.signal-man', 'label' => 'Signal Man'],
                ['id' => 'lift.communication', 'label' => 'Communication Device'],
                ['id' => 'lift.escape-routes', 'label' => 'Escape Routes to AA'],
                ['id' => 'lift.exclusion-zone', 'label' => 'Barricade & exclusion zone established'],
            ],
        ],
        'electrical-work' => [
            'label' => 'Electrical / Energized Work',
            'worstCaseScenario' => 'Electrocution, arc flash, or fire.',
            'requirements' => [
                ['id' => 'elec.loto', 'label' => 'LOTO implemented'],
                ['id' => 'elec.isolation-verified', 'label' => 'Isolation verified'],
                ['id' => 'elec.ppe', 'label' => 'Appropriate PPE'],
                ['id' => 'elec.escape-routes', 'label' => 'Escape Routes to AA'],
                ['id' => 'elec.exclusion-zone', 'label' => 'Barricade & exclusion zone established'],
            ],
        ],
    ];

    public function template(): array
    {
        return [
            'schemaVersion' => self::SCHEMA_VERSION,
            'templateVersion' => self::TEMPLATE_VERSION,
            'document' => self::DOCUMENT,
            'responseOptions' => self::RESPONSE_OPTIONS,
            'assessmentTypes' => collect($this->types())->map(
                fn (array $type, string $id): array => ['id' => $id] + $type,
            )->values()->all(),
        ];
    }

    public function type(mixed $assessmentType): ?array
    {
        $key = strtolower(trim((string) $assessmentType));
        $types = $this->types();
        if (isset($types[$key])) {
            return ['id' => $key] + $types[$key];
        }

        foreach ($types as $id => $type) {
            if (strcasecmp($type['label'], trim((string) $assessmentType)) === 0) {
                return ['id' => $id] + $type;
            }
        }

        return null;
    }

    public function formatCustomType(ErAssessmentType $type): array
    {
        return [
            'id' => (string) $type->type_key,
            'label' => (string) $type->label,
            'worstCaseScenario' => (string) ($type->worst_case_scenario ?? ''),
            'requirements' => array_values(is_array($type->requirements) ? $type->requirements : []),
            'iconKey' => (string) ($type->icon_key ?? 'ClipboardCheck'),
        ];
    }

    private function types(): array
    {
        $custom = ErAssessmentType::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->mapWithKeys(function (ErAssessmentType $type): array {
                $row = $this->formatCustomType($type);

                return [(string) $row['id'] => [
                    'label' => $row['label'],
                    'worstCaseScenario' => $row['worstCaseScenario'],
                    'requirements' => $row['requirements'],
                    'iconKey' => $row['iconKey'],
                ]];
            })
            ->all();

        return self::TYPES + $custom;
    }
}
