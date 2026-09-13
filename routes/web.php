<?php

use App\Http\Controllers\Admin\BranchController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\AreaController;
use App\Http\Controllers\CaptainBranchAssignmentController;
use App\Http\Controllers\CaptainController;
use App\Http\Controllers\CaptainEmploymentController;
use App\Http\Controllers\CaptainGroupController;
use App\Http\Controllers\CaptainGroupMembershipController;
use App\Http\Controllers\CaptainStatusController;
use App\Http\Controllers\CustomerAddressController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\CustomerPhoneController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/dashboard', DashboardController::class)
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
});

require __DIR__.'/auth.php';

Route::middleware('auth')->prefix('admin')->name('admin.')->group(function () {
    Route::resource('users', UserController::class)->except(['show', 'destroy']);
    Route::patch('users/{user}/status', [UserController::class, 'status'])->name('users.status');
    Route::resource('branches', BranchController::class)->except(['show', 'destroy']);
    Route::patch('branches/{branch}/status', [BranchController::class, 'status'])->name('branches.status');
    Route::resource('roles', RoleController::class)->except(['show', 'destroy']);
    Route::get('permissions', [RoleController::class, 'permissions'])->name('permissions.index');
});

Route::middleware('auth')->group(function () {
    Route::resource('customers', CustomerController::class)->except('destroy');
    Route::patch('customers/{customer}/status', [CustomerController::class, 'status'])->name('customers.status');
    Route::resource('customers.phones', CustomerPhoneController::class)->only(['create', 'store', 'edit', 'update', 'destroy'])->scoped();
    Route::patch('customers/{customer}/phones/{phone}/primary', [CustomerPhoneController::class, 'primary'])->name('customers.phones.primary')->scopeBindings();
    Route::resource('customers.addresses', CustomerAddressController::class)->only(['create', 'store', 'edit', 'update'])->scoped();
    Route::patch('customers/{customer}/addresses/{address}/status', [CustomerAddressController::class, 'status'])->name('customers.addresses.status')->scopeBindings();
    Route::patch('customers/{customer}/addresses/{address}/primary', [CustomerAddressController::class, 'primary'])->name('customers.addresses.primary')->scopeBindings();
    Route::resource('areas', AreaController::class)->except(['show', 'destroy']);
    Route::patch('areas/{area}/status', [AreaController::class, 'status'])->name('areas.status');
    Route::resource('captains', CaptainController::class)->except('destroy');
    Route::patch('captains/{captain}/status', CaptainStatusController::class)->name('captains.status');
    Route::patch('captains/{captain}/employment/terminate', [CaptainEmploymentController::class, 'terminate'])->name('captains.employment.terminate');
    Route::post('captains/{captain}/employment/rehire', [CaptainEmploymentController::class, 'rehire'])->name('captains.employment.rehire');
    Route::get('captains/{captain}/assignments/create', [CaptainBranchAssignmentController::class, 'create'])->name('captains.assignments.create');
    Route::post('captains/{captain}/assignments', [CaptainBranchAssignmentController::class, 'store'])->name('captains.assignments.store');
    Route::patch('captains/{captain}/assignments/{assignment}/end', [CaptainBranchAssignmentController::class, 'end'])->name('captains.assignments.end');
    Route::get('captains/{captain}/transfer', [CaptainBranchAssignmentController::class, 'transferForm'])->name('captains.transfer.create');
    Route::post('captains/{captain}/transfer', [CaptainBranchAssignmentController::class, 'transfer'])->name('captains.transfer.store');
    Route::get('captains/{captain}/groups/create', [CaptainGroupMembershipController::class, 'create'])->name('captains.groups.create');
    Route::post('captains/{captain}/groups', [CaptainGroupMembershipController::class, 'store'])->name('captains.groups.store');
    Route::patch('captains/{captain}/groups/{membership}/end', [CaptainGroupMembershipController::class, 'end'])->name('captains.groups.end');
    Route::resource('captain-groups', CaptainGroupController::class)->except(['show', 'destroy']);
    Route::patch('captain-groups/{captain_group}/status', [CaptainGroupController::class, 'status'])->name('captain-groups.status');
});
