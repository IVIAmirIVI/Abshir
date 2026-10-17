<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\User;
use App\Services\InternalNoticeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class InternalNoticeController extends Controller
{
    public function index(Request $request, InternalNoticeService $service): View
    {
        $service->assertStaff($request->user());
        $available = Schema::hasTable('notifications');

        return view('notifications.index', ['available' => $available, 'notices' => $available
            ? $service->visible($request->user())->latest()->paginate(20) : null,
            'unreadCount' => $service->unreadCount($request->user())]);
    }

    public function create(): View
    {
        Gate::authorize('manage-authorization');

        return view('notifications.create', [
            'recipients' => User::where('is_active', true)->whereHas('roles', fn ($q) => $q->whereIn('code', ['SUPER_ADMIN', 'ORDER_EMPLOYEE']))->orderBy('name')->get(),
            'branches' => Branch::orderBy('name')->get(),
        ]);
    }

    public function store(Request $request, InternalNoticeService $service): RedirectResponse
    {
        $service->send($request->user(), $request->except('_token'));

        return redirect()->route('notifications.index')->with('status', __('internal.sent'));
    }

    public function read(Request $request, string $id, InternalNoticeService $service): RedirectResponse
    {
        $service->own($request->user(), $id)->markAsRead();

        return back()->with('status', __('internal.read_done'));
    }

    public function open(Request $request, string $id, InternalNoticeService $service): RedirectResponse
    {
        return redirect()->to($service->destination($request->user(), $service->own($request->user(), $id)));
    }
}
