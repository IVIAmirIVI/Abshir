<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderBillPayment;
use App\Models\OrderBillPaymentEvidence;
use App\Models\OrderBillPaymentRequest;
use App\Services\OrderBillPaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class OrderBillPaymentController extends Controller
{
    public function request(Request $request, Order $order, OrderBillPaymentService $service): RedirectResponse
    {
        $data = $request->validate(['currency_id' => ['required', 'integer', 'exists:currencies,id']]);
        $service->request($request->user(), $order, (int) $data['currency_id']);

        return back()->with('status', 'Bill-payment service requested.');
    }

    public function cancel(Request $request, Order $order, OrderBillPaymentRequest $billPaymentRequest, OrderBillPaymentService $service): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $service->cancel($request->user(), $order, $billPaymentRequest, $data['reason']);

        return back()->with('status', 'Bill-payment request cancelled.');
    }

    public function complete(Request $request, Order $order, OrderBillPaymentRequest $billPaymentRequest, OrderBillPaymentService $service): RedirectResponse
    {
        $data = $request->validate([
            'order_captain_id' => ['required', 'integer', 'exists:order_captains,id'],
            'currency_id' => ['required', 'integer', 'exists:currencies,id'],
            'bill_number' => ['required', 'string', 'max:191'],
            'bill_date' => ['required', 'date', 'before_or_equal:today'],
            'provider_name' => ['nullable', 'string', 'max:191'],
            'principal_amount' => ['required', 'string', 'regex:/^\d+(?:\.\d{1,4})?$/'],
            'payment_reference' => ['required', 'string', 'max:191'],
            'evidence' => ['required', 'array', 'min:1', 'max:5'],
            'evidence.*' => ['required', 'file', 'max:5120'],
        ]);
        $service->complete($request->user(), $order, $billPaymentRequest, $data, $request->file('evidence'));

        return back()->with('status', 'Successful external bill payment recorded.');
    }

    public function evidence(Request $request, Order $order, OrderBillPaymentRequest $billPaymentRequest, OrderBillPayment $billPayment, OrderBillPaymentEvidence $evidence): Response
    {
        Gate::authorize('bill_payments.view');
        abort_unless(Order::query()->accessibleTo($request->user())->whereKey($order->id)->exists(), 403);
        abort_if($billPaymentRequest->order_id !== $order->id || $billPayment->request_id !== $billPaymentRequest->id || $billPayment->order_id !== $order->id || $evidence->bill_payment_id !== $billPayment->id, 404);
        abort_unless(Storage::disk(OrderBillPaymentService::DISK)->exists($evidence->storage_path), 409, 'Evidence integrity check failed.');

        try {
            $bytes = Storage::disk(OrderBillPaymentService::DISK)->get($evidence->storage_path);
        } catch (\Throwable) {
            abort(409, 'Evidence integrity check failed.');
        }
        abort_unless(strlen($bytes) === (int) $evidence->file_size
            && hash_equals($evidence->sha256, hash('sha256', $bytes))
            && (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) === $evidence->mime_type, 409, 'Evidence integrity check failed.');

        return response($bytes, 200, ['Content-Type' => $evidence->mime_type, 'Content-Disposition' => 'inline; filename="evidence-'.$evidence->id.'"']);
    }
}
