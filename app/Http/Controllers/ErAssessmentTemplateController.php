<?php

namespace App\Http\Controllers;

use App\Services\ErAssessmentCatalog;
use Illuminate\Http\JsonResponse;

final class ErAssessmentTemplateController extends Controller
{
    public function __invoke(ErAssessmentCatalog $catalog): JsonResponse
    {
        $template = $catalog->template();

        return response()->json(['data' => $template])
            ->header('ETag', '"'.hash('sha256', json_encode($template, JSON_THROW_ON_ERROR)).'"')
            ->header('Cache-Control', 'private, no-cache');
    }
}
