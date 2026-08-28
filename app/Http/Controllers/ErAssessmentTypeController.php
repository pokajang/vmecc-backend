<?php

namespace App\Http\Controllers;

use App\Models\ErAssessmentType;
use App\Services\ErAssessmentCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ErAssessmentTypeController extends Controller
{
    public function store(Request $request, ErAssessmentCatalog $catalog): JsonResponse
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:140'],
            'worstCaseScenario' => ['nullable', 'string', 'max:500'],
            'requirements' => ['required', 'array', 'min:1', 'max:10'],
            'requirements.*' => ['required', 'string', 'max:500'],
            'iconKey' => ['nullable', 'string', 'max:80'],
        ]);

        $label = Str::of((string) $data['label'])->squish()->toString();
        $typeKey = Str::slug($label);
        if ($typeKey === '') {
            throw ValidationException::withMessages([
                'label' => ['Type name must include letters or numbers.'],
            ]);
        }

        if ($catalog->type($typeKey) !== null) {
            throw ValidationException::withMessages([
                'label' => ['An ER Assessment type with this name already exists.'],
            ]);
        }

        $requirements = collect($data['requirements'])
            ->map(fn (mixed $value): string => Str::of((string) $value)->squish()->toString())
            ->filter()
            ->values();
        if ($requirements->count() === 0) {
            throw ValidationException::withMessages([
                'requirements' => ['Add at least one assessment requirement.'],
            ]);
        }
        if ($requirements->map(fn (string $value): string => Str::lower($value))->unique()->count() !== $requirements->count()) {
            throw ValidationException::withMessages([
                'requirements' => ['Assessment requirements must be unique.'],
            ]);
        }

        $type = ErAssessmentType::query()->create([
            'type_key' => $typeKey,
            'label' => $label,
            'worst_case_scenario' => Str::of((string) ($data['worstCaseScenario'] ?? ''))->squish()->toString() ?: null,
            'requirements' => $requirements
                ->values()
                ->map(fn (string $requirement, int $index): array => [
                    'id' => "{$typeKey}.requirement-".($index + 1),
                    'label' => $requirement,
                ])
                ->all(),
            'icon_key' => Str::of((string) ($data['iconKey'] ?? 'ClipboardCheck'))->squish()->toString() ?: 'ClipboardCheck',
            'is_active' => true,
            'sort_order' => (int) (ErAssessmentType::query()->max('sort_order') ?? 0) + 1,
            'created_by' => $request->user()?->id,
        ]);

        return response()->json([
            'message' => 'ER Assessment type added.',
            'data' => $catalog->formatCustomType($type),
        ], 201);
    }
}
