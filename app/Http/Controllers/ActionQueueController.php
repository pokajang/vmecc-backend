<?php

namespace App\Http\Controllers;

use App\Services\ActionQueueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ActionQueueController extends Controller
{
    public function __invoke(Request $request, ActionQueueService $actionQueue): JsonResponse
    {
        return response()->json($actionQueue->forUser($request->user()));
    }
}
