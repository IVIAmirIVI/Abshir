<?php

namespace App\Http\Controllers;

use App\Http\Requests\CaptainGroupMembershipRequest;
use App\Models\Captain;
use App\Models\CaptainGroup;
use App\Models\CaptainGroupMember;
use App\Services\CaptainGroupMembershipService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class CaptainGroupMembershipController extends Controller
{
    public function create(Request $request, Captain $captain): View
    {
        Gate::authorize('captain_groups.assign');
        abort_unless($request->user()->canAccessCaptain($captain), 403);

        return view('captains.groups.form', [
            'captain' => $captain,
            'groups' => CaptainGroup::query()->where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function store(CaptainGroupMembershipRequest $request, Captain $captain, CaptainGroupMembershipService $service): RedirectResponse
    {
        $group = CaptainGroup::findOrFail($request->validated('captain_group_id'));
        $service->join($request->user(), $captain, $group, $request->validated());

        return redirect()->route('captains.show', $captain)->with('status', 'Captain group membership created.');
    }

    public function end(Request $request, Captain $captain, CaptainGroupMember $membership, CaptainGroupMembershipService $service): RedirectResponse
    {
        $service->end($request->user(), $captain, $membership);

        return back()->with('status', 'Group membership ended; its history was preserved.');
    }
}
