<?php declare(strict_types=1);

// Routes for the separate-process App::handle() tests (SPL_ROOT = tests/web/fixtures/app).

use spl\web\Request;

return [
    'GET:/'             => fn(Request $request) => '<h1>home</h1>',
    '(GET|POST):/items' => fn(Request $request) => ['method' => $request->getMethod()],
];
