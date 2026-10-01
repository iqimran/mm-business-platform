<?php

use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::group([], base_path('app/Modules/Identity/Routes/api.php'));
    Route::group([], base_path('app/Modules/Administration/Routes/api.php'));
    Route::group([], base_path('app/Modules/Branch/Routes/api.php'));
    Route::group([], base_path('app/Modules/Audit/Routes/api.php'));
    Route::group([], base_path('app/Modules/Car/Routes/api.php'));
});
