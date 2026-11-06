<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Item;
use App\Queries\Analyzer;
use App\Support\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnalyzerController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        return response()->json(['data' => Analyzer::fromRequest($request)->summary()]);
    }

    public function items(Request $request): JsonResponse
    {
        return response()->json(ListQuery::envelope(Analyzer::fromRequest($request)->paginate($request)));
    }

    public function sources(Request $request, Item $item): JsonResponse
    {
        return response()->json(['data' => Analyzer::fromRequest($request)->sources($item->id)]);
    }

    public function statusSummary(Request $request): JsonResponse
    {
        return response()->json(['data' => Analyzer::fromRequest($request)->statusSummary()]);
    }
}
