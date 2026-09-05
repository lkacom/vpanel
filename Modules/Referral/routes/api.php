<?php

use Illuminate\Support\Facades\Route;
use Modules\Referral\Http\Controllers\ReferralController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::apiResource('referrals', ReferralController::class)->names('referral');
});
