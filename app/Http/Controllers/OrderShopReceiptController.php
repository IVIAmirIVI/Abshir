<?php

namespace App\Http\Controllers;

use App\Http\Requests\OrderShopReceiptRequest;
use App\Models\Order;
use App\Models\OrderShop;
use App\Models\OrderShopReceipt;
use App\Services\OrderShopReceiptService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OrderShopReceiptController extends Controller
{
    public function store(OrderShopReceiptRequest $request, Order $order, OrderShop $orderShop, OrderShopReceiptService $service): RedirectResponse
    {
        $service->upload($request->user(), $order, $orderShop, $request->file('receipt'), $request->validated('note'));

        return redirect()->route('orders.workflow', [$order, 'step' => 'shops'])->with('status', __('orders.flash.receipt_uploaded'));
    }

    public function show(Request $request, Order $order, OrderShop $orderShop, OrderShopReceipt $receipt, OrderShopReceiptService $service): StreamedResponse
    {
        $service->authorizeView($request->user(), $order, $orderShop, $receipt);
        abort_unless(Storage::disk($service->disk())->exists($receipt->storage_path), 404);

        $safeFilename = 'receipt-'.$receipt->id.'.'.pathinfo($receipt->storage_path, PATHINFO_EXTENSION);

        return Storage::disk($service->disk())->response($receipt->storage_path, $safeFilename, ['Content-Type' => $receipt->mime_type, 'Content-Disposition' => 'inline; filename="'.$safeFilename.'"']);
    }
}
