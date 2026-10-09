<?php

use App\Http\Controllers\V2\ProviderWebhookController;
use App\Http\Controllers\Web\BonusWebController;
use App\Http\Controllers\Web\LicenseSignupWebController;
use App\Http\Controllers\Webhooks\JvzooWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('webhooks/jvzoo', [JvzooWebhookController::class, 'handle'])->name('webhooks.jvzoo');
Route::post('unipile/callback', [ProviderWebhookController::class, 'unipile'])->name('unipile.callback');

Route::get('auth/fe', [LicenseSignupWebController::class, 'showFe'])->name('license.fe');
Route::post('auth/fe', [LicenseSignupWebController::class, 'storeFe'])->name('license.fe.store');
Route::get('auth/bundle-access', [LicenseSignupWebController::class, 'showBundle'])->name('license.bundle');
Route::post('auth/bundle-access', [LicenseSignupWebController::class, 'storeBundle'])->name('license.bundle.store');
Route::get('create-reseller', [LicenseSignupWebController::class, 'showReseller'])->name('license.reseller');
Route::post('auth/reseller-access', [LicenseSignupWebController::class, 'storeReseller'])->name('license.reseller.store');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::middleware('entitlement:Bundle')->group(function () {
        Route::get('bonus/upsell-unlimited', [BonusWebController::class, 'upsellUnlimited'])->name('bonus.upsell-unlimited');
        Route::get('bonus/market-agency-setup', [BonusWebController::class, 'marketAgencySetup'])->name('bonus.market-agency');
        Route::get('bonus/dfy-campaign', [BonusWebController::class, 'dfyCampaign'])->name('bonus.dfy-campaign');
        Route::get('bonus/coach-program', [BonusWebController::class, 'coachProgram'])->name('bonus.coach-program');
        Route::get('bonus/unlimited-traffic', [BonusWebController::class, 'unlimitedTraffic'])->name('bonus.unlimited-traffic');

        Route::get('upsell-unlimited', fn () => redirect()->route('bonus.upsell-unlimited'));
        Route::get('market-agency-setup', fn () => redirect()->route('bonus.market-agency'));
        Route::get('dfy-campaign', fn () => redirect()->route('bonus.dfy-campaign'));
        Route::get('coach-program', fn () => redirect()->route('bonus.coach-program'));
        Route::get('unlimited-traffic', fn () => redirect()->route('bonus.unlimited-traffic'));
    });

    Route::get('affiliate-campaign-vault', [BonusWebController::class, 'affiliateCampaignVault'])
        ->middleware('entitlement:AffiliateCampaignVault')->name('addons.affiliate-campaign-vault');
    Route::get('profit-multiplier', [BonusWebController::class, 'profitMultiplier'])
        ->middleware('entitlement:ProfitMultiplier')->name('addons.profit-multiplier');
});
