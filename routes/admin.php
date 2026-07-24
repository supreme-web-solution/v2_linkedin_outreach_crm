<?php

use App\Http\Controllers\Web\AdminUsersWebController;
use App\Http\Controllers\Web\ResellerUsersWebController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::post('admin/stop-impersonating', [AdminUsersWebController::class, 'stopImpersonating'])
        ->name('admin.stop-impersonating');

    Route::middleware('platform.admin')->prefix('admin')->group(function () {
        Route::get('users', [AdminUsersWebController::class, 'index'])->name('admin.users');
        Route::post('users', [AdminUsersWebController::class, 'store'])->name('admin.users.store');
        Route::put('users/{id}', [AdminUsersWebController::class, 'update'])->whereNumber('id')->name('admin.users.update');
        Route::delete('users/{id}', [AdminUsersWebController::class, 'destroy'])->whereNumber('id')->name('admin.users.destroy');
        Route::put('users/entitlements', [AdminUsersWebController::class, 'assignEntitlements'])->name('admin.users.entitlements');
        Route::get('users/{id}/permissions', [AdminUsersWebController::class, 'permissions'])->whereNumber('id')->name('admin.users.permissions');
        Route::post('users/{id}/impersonate', [AdminUsersWebController::class, 'impersonate'])->whereNumber('id')->name('admin.users.impersonate');
    });

    Route::middleware('reseller')->prefix('reseller')->group(function () {
        Route::get('users', [ResellerUsersWebController::class, 'index'])->name('reseller.users');
        Route::post('users', [ResellerUsersWebController::class, 'store'])->name('reseller.users.store');
        Route::put('users/{id}', [ResellerUsersWebController::class, 'update'])->whereNumber('id')->name('reseller.users.update');
        Route::delete('users/{id}', [ResellerUsersWebController::class, 'destroy'])->whereNumber('id')->name('reseller.users.destroy');
    });
});
