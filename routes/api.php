<?php

use Illuminate\Support\Facades\Route;

Route::prefix('v2')->middleware(['api'])->group(function () {
    require base_path('routes/api_v2.php');
});
