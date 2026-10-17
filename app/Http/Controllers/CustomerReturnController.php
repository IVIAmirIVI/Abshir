<?php

namespace App\Http\Controllers;

use App\Models\CustomerReturnCase;
use App\Models\CustomerReturnMovement;
use App\Models\Order;
use App\Services\CustomerRefundEvidenceService;
use App\Services\CustomerReturnService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CustomerReturnController extends Controller
{
    public function store(Request $request, Order $order, CustomerReturnService $service): RedirectResponse
    {
        $service->authorize($request->user(), $order, true);
        $request->validate(['lines' => ['required', 'array'], 'lines.*' => ['array'], 'responsibilities' => ['required', 'array'], 'responsibilities.*' => ['array']]);
        $data = $request->except('_token');
        $data['lines'] = array_values(array_filter($data['lines'] ?? [], fn ($line) => filled($line['amount'] ?? null) || (($line['kind'] ?? null) === 'PRODUCT' && filled($line['quantity'] ?? null))));
        $data['responsibilities'] = array_values(array_filter($data['responsibilities'] ?? [], fn ($party) => filled($party['amount'] ?? null)));
        $service->record($request->user(), $order, $data);

        return redirect()->route('orders.workflow', $order)->with('status', __('returns.saved'));
    }

    public function movement(Request $request, Order $order, CustomerReturnCase $returnCase, CustomerReturnService $service): RedirectResponse
    {
        $service->authorize($request->user(), $order, true);
        abort_unless($returnCase->order_id === $order->id, 404);
        $request->validate(['evidence' => ['required', 'file']]);
        $service->move($request->user(), $returnCase, $request->except('_token', 'evidence'), $request->file('evidence'));

        return redirect()->route('orders.workflow', $order)->with('status', __('returns.money_saved'));
    }

    public function evidence(Request $request, Order $order, CustomerReturnMovement $movement, CustomerReturnService $service, CustomerRefundEvidenceService $evidence): Response
    {
        $service->authorize($request->user(), $order);
        abort_unless(CustomerReturnCase::whereKey($movement->case_id)->where('order_id', $order->id)->exists(), 404);

        return response($evidence->bytes($movement->evidence_path, $movement->evidence_sha256, $movement->evidence_mime, $movement->evidence_size), 200,
            ['Content-Type' => $movement->evidence_mime, 'Content-Disposition' => 'attachment; filename="return-evidence-'.$movement->id.'"', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
