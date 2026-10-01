<?php

namespace App\Models;

use App\Models\Concerns\HasImmutableFinancialHistory;
use Illuminate\Database\Eloquent\Model;

class InvoiceCorrectionReceivableDispositionAllocation extends Model
{
    use HasImmutableFinancialHistory;

    protected $table = 'invoice_correction_receivable_disposition_allocations';

    public $timestamps = false;

    protected $guarded = [];
}
