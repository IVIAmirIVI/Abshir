<?php

namespace App\Http\Controllers;

use App\Enums\OrderCancellationReason;
use App\Models\Order;
use App\Models\OrderShop;
use App\Models\OrderShopPurchaseConfirmation;
use App\Models\OrderShopReturn;
use App\Models\OrderShopReturnEvidence;
use App\Services\OrderCancellationFinalizationService;
use App\Services\OrderCancellationIntentService;
use App\Services\OrderReturnEvidenceService;
use App\Services\OrderShopPurchaseConfirmationService;
use App\Services\OrderShopReturnService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OrderReturnController extends Controller
{
    public function purchase(Request $request, Order $order, OrderShop $orderShop, OrderShopPurchaseConfirmationService $service): RedirectResponse
    {
        $data = $request->validate([
            'order_captain_id' => ['required', 'integer'], 'currency_id' => ['required', 'integer'],
            'amount' => ['required', 'string'], 'purchased_at' => ['required', 'date'],
            'request_key' => ['required', 'uuid'], 'reference' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:3000'], 'evidence' => ['required', 'file', 'max:5120'],
        ]);
        $service->record($request->user(), $order, $orderShop, $data, $request->file('evidence'));

        return back()->with('status', 'Authoritative Shop purchase recorded.');
    }

    public function intent(Request $request, Order $order, OrderCancellationIntentService $service): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', Rule::enum(OrderCancellationReason::class)],
            'notes' => ['nullable', 'string', 'max:3000'], 'request_key' => ['required', 'uuid'],
        ]);
        $service->create($request->user(), $order, OrderCancellationReason::from($data['reason']), $data['notes'] ?? null, $data['request_key']);

        return back()->with('status', 'Cancellation intent recorded. Complete each purchased Shop return before final cancellation.');
    }

    public function returnShop(Request $request, Order $order, OrderShop $orderShop, OrderShopReturnService $service): RedirectResponse
    {
        $data = $request->validate([
            'returning_order_captain_id' => ['required', 'integer'], 'refund_amount' => ['required', 'string'],
            'returned_at' => ['required', 'date'], 'refunded_at' => ['required', 'date'],
            'refund_method' => ['required', 'string', 'max:40'], 'refund_reference' => ['nullable', 'string', 'max:255'],
            'shortfall_reason' => ['nullable', 'string', 'max:3000'], 'shortfall_acknowledged' => ['sometimes', 'boolean'],
            'note' => ['nullable', 'string', 'max:3000'],
            'request_key' => ['required', 'uuid'], 'return_proof' => ['nullable', 'file', 'max:5120'],
            'refund_proof' => ['nullable', 'file', 'max:5120'], 'combined_proof' => ['nullable', 'file', 'max:5120'],
        ]);
        $files = array_filter([
            'RETURN_PROOF' => $request->file('return_proof'),
            'REFUND_PROOF' => $request->file('refund_proof'),
            'RETURN_AND_REFUND_PROOF' => $request->file('combined_proof'),
        ]);
        $service->record($request->user(), $order, $orderShop, $data, $files);

        return back()->with('status', 'Shop return and refund evidence recorded.');
    }

    public function finalize(Request $request, Order $order, OrderCancellationFinalizationService $service): RedirectResponse
    {
        $service->finalize($request->user(), $order);

        return back()->with('status', 'Order cancelled after all purchased Shops were returned.');
    }

    public function purchaseEvidence(Request $request, Order $order, OrderShop $orderShop, OrderShopPurchaseConfirmation $purchase, OrderReturnEvidenceService $evidence): StreamedResponse
    {
        Gate::authorize('order_shop_purchases.view');
        $this->authorizeNested($request, $order, $orderShop);
        abort_unless($purchase->order_id === $order->id && $purchase->order_shop_id === $orderShop->id, 404);

        $bytes = $evidence->bytes($purchase->evidence_path, $purchase->evidence_sha256, $purchase->evidence_size, $purchase->evidence_mime);

        return response()->stream(static function () use ($bytes): void {
            echo $bytes;
        }, 200, [
            'Content-Type' => $purchase->evidence_mime,
            'Content-Disposition' => 'inline; filename="purchase-evidence"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function returnEvidence(Request $request, Order $order, OrderShop $orderShop, OrderShopReturn $shopReturn, OrderShopReturnEvidence $proof, OrderReturnEvidenceService $evidence): StreamedResponse
    {
        Gate::authorize('order_shop_return_evidence.download');
        $this->authorizeNested($request, $order, $orderShop);
        abort_unless($shopReturn->order_id === $order->id && $shopReturn->order_shop_id === $orderShop->id && $proof->order_shop_return_id === $shopReturn->id, 404);

        $bytes = $evidence->bytes($proof->storage_path, $proof->sha256, $proof->file_size, $proof->mime_type);

        return response()->stream(static function () use ($bytes): void {
            echo $bytes;
        }, 200, [
            'Content-Type' => $proof->mime_type,
            'Content-Disposition' => 'inline; filename="return-evidence"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function authorizeNested(Request $request, Order $order, OrderShop $orderShop): void
    {
        abort_unless(Order::query()->accessibleTo($request->user())->whereKey($order)->exists(), 403);
        abort_unless($orderShop->order_id === $order->id, 404);
    }
}
