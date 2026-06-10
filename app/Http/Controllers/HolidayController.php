<?php

namespace App\Http\Controllers;

use App\Models\Holiday;
use App\Models\HolidayHistory;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class HolidayController extends Controller
{
    private const SCOPES = ['National', 'State'];

    // ── List ──────────────────────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $query = Holiday::query();

        if ($request->filled('year')) {
            $query->where('year', (int) $request->input('year'));
        }
        if ($request->filled('scope') && $request->input('scope') !== 'All') {
            $query->where('scope', $request->input('scope'));
        }
        if ($request->filled('state') && $request->input('state') !== 'All') {
            $query->where('state', $request->input('state'));
        }
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('date', 'like', "%{$search}%")
                  ->orWhere('state', 'like', "%{$search}%")
                  ->orWhere('scope', 'like', "%{$search}%");
            });
        }

        $sort = $request->input('sort', 'date:asc');
        [$col, $dir] = array_pad(explode(':', $sort), 2, 'asc');
        $allowedSorts = ['date', 'name', 'scope', 'state', 'year'];
        $col  = in_array($col, $allowedSorts, true) ? $col : 'date';
        $dir  = $dir === 'desc' ? 'desc' : 'asc';
        $query->orderBy($col, $dir)->orderBy('id', $dir);

        return response()->json(['data' => $query->get()->map(fn ($h) => $this->format($h))]);
    }

    // ── Wizard batch save (transactional) ─────────────────────────────────────
    // Accepts nationals (upserted by fixed_holiday_key + year) and
    // ad-hoc additionals (inserted, skipping exact duplicates).
    // A single DB transaction means either everything saves or nothing does.

    public function batch(Request $request): JsonResponse
    {
        $data = $request->validate([
            'nationals'                         => ['present', 'array'],
            'nationals.*.fixed_holiday_key'     => ['required', 'string', 'max:100'],
            'nationals.*.name'                  => ['required', 'string', 'max:255'],
            'nationals.*.date'                  => ['required', 'date_format:Y-m-d'],
            'nationals.*.applicable'            => ['required', 'boolean'],

            'additionals'                       => ['present', 'array'],
            'additionals.*.name'                => ['required', 'string', 'max:255'],
            'additionals.*.date'                => ['required', 'date_format:Y-m-d'],
            'additionals.*.scope'               => ['required', Rule::in(self::SCOPES)],
            'additionals.*.state'               => ['nullable', 'string', 'max:100'],
        ]);

        $actor    = $request->user();
        $saved    = [];
        $skipped  = [];

        DB::transaction(function () use ($data, $actor, &$saved, &$skipped) {
            // ── Nationals: upsert by (fixed_holiday_key, year) ────────────────
            // We fetch withTrashed() first so we can manually restore soft-deleted
            // rows. This avoids the unique constraint firing on a trashed record
            // before updateOrCreate() can overwrite it.
            foreach ($data['nationals'] as $row) {
                $year = (int) date('Y', strtotime($row['date']));

                $existing = Holiday::withTrashed()
                    ->where('fixed_holiday_key', $row['fixed_holiday_key'])
                    ->where('year', $year)
                    ->first();

                if (!$row['applicable']) {
                    // Deselected — soft-delete only if a live row exists
                    if ($existing && !$existing->trashed()) {
                        HolidayHistory::create([
                            'holiday_id'    => $existing->id,
                            'actor_user_id' => $actor->id,
                            'action'        => 'deleted',
                            'changes'       => $this->snapshot($existing),
                        ]);
                        $existing->delete();
                    }
                    continue;
                }

                $isNew  = !$existing || $existing->trashed();
                $before = ($existing && !$existing->trashed()) ? $this->snapshot($existing) : null;

                if ($existing) {
                    // Restore if trashed, then update in-place to avoid unique constraint collision
                    $existing->restore();
                    $existing->update([
                        'name'                => $row['name'],
                        'date'                => $row['date'],
                        'year'                => $year,
                        'scope'               => 'National',
                        'state'               => 'All States',
                        'is_default_national' => true,
                    ]);
                    $holiday = $existing->fresh();
                } else {
                    $holiday = Holiday::create([
                        'fixed_holiday_key'   => $row['fixed_holiday_key'],
                        'name'                => $row['name'],
                        'date'                => $row['date'],
                        'year'                => $year,
                        'scope'               => 'National',
                        'state'               => 'All States',
                        'is_default_national' => true,
                    ]);
                }

                HolidayHistory::create([
                    'holiday_id'    => $holiday->id,
                    'actor_user_id' => $actor->id,
                    'action'        => $isNew ? 'created' : 'updated',
                    'changes'       => $isNew
                        ? $this->snapshot($holiday)
                        : ['from' => $before, 'to' => $this->snapshot($holiday)],
                ]);

                $saved[] = $holiday;
            }

            // ── Additionals: insert, skip exact duplicates ────────────────────
            // withTrashed() ensures a previously soft-deleted entry is not treated
            // as absent, preventing ghost re-creation and duplicate constraint errors.
            foreach ($data['additionals'] as $row) {
                $scope = $row['scope'] === 'State' ? 'State' : 'National';
                $state = $scope === 'State' ? ($row['state'] ?? 'All States') : 'All States';
                $year  = (int) date('Y', strtotime($row['date']));

                $existingAdhoc = Holiday::withTrashed()
                    ->where('name', $row['name'])
                    ->where('date', $row['date'])
                    ->where('scope', $scope)
                    ->where('state', $state)
                    ->first();

                if ($existingAdhoc) {
                    // Already exists (live or soft-deleted) — skip to avoid duplicates
                    $skipped[] = $row['name'];
                    continue;
                }

                $holiday = Holiday::create([
                    'name'                => $row['name'],
                    'date'                => $row['date'],
                    'year'                => $year,
                    'scope'               => $scope,
                    'state'               => $state,
                    'is_default_national' => false,
                    'fixed_holiday_key'   => null,
                ]);

                HolidayHistory::create([
                    'holiday_id'    => $holiday->id,
                    'actor_user_id' => $actor->id,
                    'action'        => 'created',
                    'changes'       => $this->snapshot($holiday),
                ]);

                $saved[] = $holiday;
            }
        });

        AuditLogger::log($request, 'holidays_batch_saved', null, [
            'saved_count'   => count($saved),
            'skipped_count' => count($skipped),
            'skipped_names' => $skipped,
        ]);

        return response()->json([
            'data'    => array_map(fn ($h) => $this->format($h), $saved),
            'skipped' => $skipped,
            'message' => count($saved) . ' holiday(s) saved.'
                . (count($skipped) > 0 ? ' ' . count($skipped) . ' duplicate(s) skipped.' : ''),
        ], 201);
    }

    // ── Single update (table edit pencil) ─────────────────────────────────────

    public function update(Request $request, int $id): JsonResponse
    {
        $holiday = Holiday::findOrFail($id);
        $actor   = $request->user();

        $data = $request->validate([
            'name'  => ['required', 'string', 'max:255'],
            'date'  => ['required', 'date_format:Y-m-d'],
            'scope' => ['required', Rule::in(self::SCOPES)],
            'state' => ['nullable', 'string', 'max:100'],
        ]);

        $scope  = $data['scope'] === 'State' ? 'State' : 'National';
        $state  = $scope === 'State' ? ($data['state'] ?? 'All States') : 'All States';
        $year   = (int) date('Y', strtotime($data['date']));
        $before = $this->snapshot($holiday);

        DB::transaction(function () use ($holiday, $data, $scope, $state, $year, $actor, $before) {
            $holiday->update([
                'name'  => $data['name'],
                'date'  => $data['date'],
                'year'  => $year,
                'scope' => $scope,
                'state' => $state,
            ]);

            HolidayHistory::create([
                'holiday_id'    => $holiday->id,
                'actor_user_id' => $actor->id,
                'action'        => 'updated',
                'changes'       => ['from' => $before, 'to' => $this->snapshot($holiday)],
            ]);
        });

        AuditLogger::log($request, 'holiday_updated', null, ['holiday_id' => $holiday->id]);

        return response()->json(['data' => $this->format($holiday)]);
    }

    // ── Delete ────────────────────────────────────────────────────────────────

    public function destroy(Request $request, int $id): JsonResponse
    {
        $holiday = Holiday::findOrFail($id);
        $actor   = $request->user();

        DB::transaction(function () use ($holiday, $actor) {
            HolidayHistory::create([
                'holiday_id'    => $holiday->id,
                'actor_user_id' => $actor->id,
                'action'        => 'deleted',
                'changes'       => $this->snapshot($holiday),
            ]);
            $holiday->delete();
        });

        AuditLogger::log($request, 'holiday_deleted', null, ['holiday_id' => $id]);

        return response()->json(['message' => 'Holiday deleted.']);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function format(Holiday $h): array
    {
        return [
            'id'                  => $h->id,
            'name'                => $h->name,
            'date'                => $h->date instanceof \Carbon\Carbon
                ? $h->date->format('Y-m-d')
                : (string) $h->date,
            'year'                => $h->year,
            'scope'               => $h->scope,
            'state'               => $h->state,
            'is_default_national' => $h->is_default_national,
            'fixed_holiday_key'   => $h->fixed_holiday_key,
            'created_at'          => $h->created_at?->toISOString(),
            'updated_at'          => $h->updated_at?->toISOString(),
        ];
    }

    private function snapshot(Holiday $h): array
    {
        return [
            'name'  => $h->name,
            'date'  => $h->date instanceof \Carbon\Carbon
                ? $h->date->format('Y-m-d')
                : (string) $h->date,
            'scope' => $h->scope,
            'state' => $h->state,
        ];
    }
}
