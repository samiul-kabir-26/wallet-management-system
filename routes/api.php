<?php

use Illuminate\Support\Facades\Route;

Route::prefix('v1/auth')->group(base_path('Modules/Authentication/routes.php'));
