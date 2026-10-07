<?php

namespace App\Models;

use App\Enums\InvoiceFinancialCorrectionKind;
use App\Enums\InvoiceFinancialCorrectionState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class InvoiceFinancialCorrection extends Model
{
    public $timestamps = false;

    // These PostgreSQL columns are timestamptz: retain the offset at the write boundary.
    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'receivable_contract_version' => 'integer',
            'receivable_dispositions_captured' => 'boolean',
            'kind' => InvoiceFinancialCorrectionKind::class,
            'state' => InvoiceFinancialCorrectionState::class,
            'proposal_payload' => 'array',
            'original_amount' => 'decimal:4',
            'corrected_amount' => 'decimal:4',
            'customer_delta' => 'decimal:4',
            'initiated_at' => 'datetime',
            'prepared_at' => 'datetime',
            'finalized_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function originalInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'original_invoice_id');
    }

    public function originalCollection(): BelongsTo
    {
        return $this->belongsTo(InvoiceCollection::class, 'original_collection_id');
    }

    public function refund(): HasOne
    {
        return $this->hasOne(CustomerRefund::class);
    }

    public function receivableDispositions(): HasMany
    {
        return $this->hasMany(InvoiceCorrectionReceivableDisposition::class, 'invoice_financial_correction_id');
    }

    public function recoveries(): HasMany
    {
        return $this->hasMany(CaptainReceivableRecovery::class);
    }
}
