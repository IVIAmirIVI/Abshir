<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SecurityAudit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class SecurityAuditController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('manage-authorization');
        $filters = $request->validate([
            'actor_id' => ['nullable', 'integer', 'min:1'],
            'resource_kind' => ['nullable', 'in:USER,ROLE,BRANCH,NOTICE'],
            'resource_key' => ['nullable', 'string', 'max:64'],
            'action' => ['nullable', 'in:USER_CREATED,USER_ACCESS_CHANGED,USER_STATUS_CHANGED,ROLE_CREATED,ROLE_ACCESS_CHANGED,BRANCH_CREATED,BRANCH_ACCESS_CHANGED,BRANCH_STATUS_CHANGED,NOTICE_SENT'],
            'branch_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $available = Schema::hasTable('security_audits');
        $rows = null;
        if ($available) {
            $query = SecurityAudit::query()->with(['actor', 'branch']);
            foreach ($filters as $field => $value) {
                if (filled($value)) {
                    $query->where($field, $value);
                }
            }
            $rows = $query->latest('id')->paginate(25)->withQueryString();
        }

        return view('admin.security-audit', ['available' => $available, 'audits' => $rows,
            'branches' => $request->user()->availableBranches()->orderBy('name')->get(), 'filters' => $filters]);
    }
}
