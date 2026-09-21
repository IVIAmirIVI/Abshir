<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Enums\OrderOperationalStatus;
use App\Models\Order;
use Illuminate\Validation\ValidationException;

class OrderMutationGuard
{
    public function assertMutableLocked(Order $order): void
    {
        if ($order->operational_status === OrderOperationalStatus::Delivered) {
            throw ValidationException::withMessages(['order' => 'A delivered Order cannot be changed.']);
        }

        if ($order->operational_status === OrderOperationalStatus::Cancelled) {
            throw ValidationException::withMessages(['order' => 'A cancelled Order cannot be changed.']);
        }

        if ($order->currentInvoice()->where('status', InvoiceStatus::Issued->value)->lockForUpdate()->first()) {
            throw ValidationException::withMessages(['order' => 'The Order cannot be changed while its current Invoice is issued.']);
        }
    }

    public function assertOperationalEvidenceMutableLocked(Order $order): void
    {
        if ($order->operational_status === OrderOperationalStatus::Delivered) {
            throw ValidationException::withMessages(['order' => 'A delivered Order cannot be changed.']);
        }

        if ($order->operational_status === OrderOperationalStatus::Cancelled) {
            throw ValidationException::withMessages(['order' => 'A cancelled Order cannot be changed.']);
        }
    }
}
