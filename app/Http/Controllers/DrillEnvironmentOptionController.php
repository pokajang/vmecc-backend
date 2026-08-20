<?php

namespace App\Http\Controllers;

use App\Models\DrillEnvironmentOption;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class DrillEnvironmentOptionController extends Controller
{
    private const MAX_ENVIRONMENTS = 100;

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $rows = DrillEnvironmentOption::query()
            ->where('user_id', $user->id)
            ->orderBy('id')
            ->get()
            ->map(fn (DrillEnvironmentOption $row) => $this->formatRow($row))
            ->values()
            ->all();

        return response()->json(['data' => $rows]);
    }

    public function replace(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'options' => ['required', 'array'],
            'options.*.value' => ['required', 'string', 'max:140'],
            'options.*.title' => ['nullable', 'string', 'max:140'],
            'options.*.description' => ['nullable', 'string', 'max:500'],
            'options.*.iconKey' => ['nullable', 'string', 'max:80'],
            'options.*.icon_key' => ['nullable', 'string', 'max:80'],
        ]);

        $incoming = $this->normalizeRows($data['options'] ?? []);
        if (count($incoming) > self::MAX_ENVIRONMENTS) {
            return response()->json([
                'message' => 'Too many environment options.',
                'code' => 'drill_environment_option_limit_reached',
            ], 422);
        }

        $rowsByKey = [];
        foreach ($incoming as $row) {
            $key = $row['value'];
            $rowsByKey[$key] = [
                'value' => $row['value'],
                'title' => $row['title'],
                'description' => $row['description'],
                'icon_key' => $row['icon_key'],
            ];
        }
        $normalized = array_values($rowsByKey);

        DrillEnvironmentOption::query()->where('user_id', $user->id)->delete();
        foreach ($normalized as $row) {
            DrillEnvironmentOption::create([
                'user_id' => $user->id,
                'value' => $row['value'],
                'title' => $row['title'],
                'description' => $row['description'],
                'icon_key' => $row['icon_key'],
            ]);
        }

        return response()->json([
            'message' => 'Drill environment options saved.',
            'data' => $normalized,
        ]);
    }

    private function normalizeRows(array $rows): array
    {
        $normalized = [];
        foreach ($rows as $row) {
            $value = trim((string) ($row['value'] ?? ''));
            if ($value === '') continue;

            $title = trim((string) ($row['title'] ?? $row['value']));
            if ($title === '') $title = $value;

            $normalized[] = [
                'value' => $value,
                'title' => $title,
                'description' => trim((string) ($row['description'] ?? '')),
                'icon_key' => trim((string) ($row['iconKey'] ?? $row['icon_key'] ?? '')),
            ];
        }

        usort($normalized, function ($left, $right) {
            return Str::lower($left['value']) <=> Str::lower($right['value']);
        });

        return $normalized;
    }

    private function formatRow(DrillEnvironmentOption $row): array
    {
        return [
            'value' => (string) $row->value,
            'title' => (string) $row->title,
            'description' => (string) ($row->description ?? ''),
            'iconKey' => (string) ($row->icon_key ?? ''),
        ];
    }
}
