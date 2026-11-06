<?php

use Illuminate\Support\Facades\Route;

// The React SPA handles every page route; API, auth, docs and health routes are registered separately.
Route::view('/{path?}', 'app')->where('path', '^(?!api/|sanctum/|docs/|up$|storage/).*$')->name('spa');
