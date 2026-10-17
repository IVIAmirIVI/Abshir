<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CaptainPortalOrderResource;
use App\Services\CaptainPortalQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Opt-in same-origin session reads only; this is not external bearer-token authentication. */
class CaptainPortalController extends Controller
{
    private function enabled(): void
    {
        abort_unless(config('captain_portal.session_api_enabled') === true, 404);
    }

    public function me(Request $request, CaptainPortalQuery $portal): JsonResponse
    {
        $this->enabled();
        $captain = $portal->identity($request->user());

        return response()->json(['data' => ['user_id' => $request->user()->id, 'name' => $request->user()->name,
            'captain_id' => $captain->id], 'contract' => 'session-read-only-v1'])
            ->header('Cache-Control', 'private, no-store');
    }

    public function index(Request $request, CaptainPortalQuery $portal): JsonResponse
    {
        $this->enabled();
        $result = $portal->listing($request->user(), $request->query());
        $page = $result['page'];

        // No arbitrary query parameters (including credentials or forged IDs) in pagination URLs.
        return response()->json(['data' => CaptainPortalOrderResource::collection($page->getCollection())->resolve($request),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(), 'total' => $page->total(), 'filters' => $result['filters']]])
            ->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, int $order, CaptainPortalQuery $portal): JsonResponse
    {
        $this->enabled();
        $detail = $portal->detail($request->user(), $order);

        // API foundation intentionally stays minimal; contact, evidence and invoice fields remain HTML-only.
        return response()->json(['data' => (new CaptainPortalOrderResource($detail['order']))->resolve($request),
            'meta' => ['work_details_available_in_portal' => $detail['work']]])->header('Cache-Control', 'private, no-store');
    }
}
