<?php

namespace App\Http\Controllers;

/**
 * Serves the built React single-page application.
 *
 * The SPA is the default user-facing application. Laravel keeps ownership of
 * the API (/api/v1), the emailed public invitation route (/invite/{token}), the
 * health endpoint (/up) and legacy Blade pages. All other GET navigation is
 * handed to the SPA so React client routes work on direct load and refresh.
 */
class SpaController extends Controller
{
    public function __invoke()
    {
        $index = public_path('app/index.html');

        abort_unless(
            is_file($index),
            404,
            'Frontend build not found. Run: cd frontend && npm run build'
        );

        return response(file_get_contents($index), 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
        ]);
    }
}
