<?php

namespace App\Http\Controllers;

use App\Models\InspectionLocation;
use App\Services\AssignmentAuthorizationService;
use App\Services\InspectionSiteLocationCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class InspectionSiteLocationController extends Controller
{
    public function __construct(
        private readonly AssignmentAuthorizationService $authorizationService,
        private readonly InspectionSiteLocationCatalogService $catalog,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->ensurePermission($request);
        $rows = $this->catalog->hierarchy();

        return response()->json([
            'data' => $rows->values(),
            'meta' => ['count' => $rows->count(), 'source' => 'database', 'scope' => 'site'],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->ensurePermission($request);
        $data = $request->validate($this->rules(requireLevel: true));

        try {
            $result = $this->catalog->create($data, $request->user()?->id);
        } catch (\InvalidArgumentException $error) {
            throw ValidationException::withMessages(['parentId' => $error->getMessage()]);
        }

        if (! $result['created']) {
            if ($result['scopeConflict'] ?? false) {
                return response()->json([
                    'code' => 'SITE_LOCATION_SCOPE_CONFLICT',
                    'message' => 'This name is already used by a separate inspection catalogue.',
                ], Response::HTTP_CONFLICT);
            }

            return $this->duplicateResponse($result['row'], $result['level']);
        }

        return response()->json([
            'data' => $this->catalog->formatNode($result['row'], $result['level']),
        ], Response::HTTP_CREATED);
    }

    public function update(Request $request, int $locationId): JsonResponse
    {
        $this->ensurePermission($request);
        $result = $this->catalog->update(
            $locationId,
            $request->validate($this->rules(requireLevel: false))
        );
        if ($result['duplicate']) {
            return $this->duplicateResponse($result['duplicate'], $result['level']);
        }

        return response()->json([
            'data' => $this->catalog->formatNode($result['row'], $result['level']),
        ]);
    }

    public function destroy(Request $request, int $locationId): Response
    {
        $this->ensurePermission($request);
        $this->catalog->archive($locationId);

        return response()->noContent();
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function rules(bool $requireLevel): array
    {
        return [
            'level' => [$requireLevel ? 'required' : 'sometimes', Rule::in(InspectionSiteLocationCatalogService::LEVELS)],
            'parentId' => ['nullable', 'integer'],
            'name' => ['required', 'string', 'max:190'],
            'description' => ['nullable', 'string', 'max:500'],
            'iconKey' => ['nullable', 'string', 'max:80'],
        ];
    }

    private function duplicateResponse(InspectionLocation $row, string $level): JsonResponse
    {
        return response()->json([
            'code' => 'SITE_LOCATION_ALREADY_EXISTS',
            'message' => 'An active site location with this name already exists under the selected parent.',
            'data' => ['existing' => $this->catalog->formatNode($row, $level)],
        ], Response::HTTP_CONFLICT);
    }

    private function ensurePermission(Request $request): void
    {
        $user = $request->user();
        if (! $user || ! $this->authorizationService->hasPermission($user, 'reports.manage|reports.inspection.view')) {
            abort(403, 'Missing inspection report permission.');
        }
    }
}
