<?php

namespace App\Models;

use App\Models\Concerns\HasImmutableFinancialHistory;
use Illuminate\Database\Eloquent\Model;

class CaptainReceivableRecoveryAllocation extends Model
{
    use HasImmutableFinancialHistory;

    public $timestamps = false;

    protected $guarded = [];
}
