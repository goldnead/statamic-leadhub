<?php

use Goldnead\Leadhub\Http\Controllers\Web\TrackingController;
use Goldnead\Leadhub\Services\ClickTracking\ClickTrackingLinker;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| LeadHub front-end routes
|--------------------------------------------------------------------------
|
| Mounted by Statamic's AddonServiceProvider at the site root (see the
| `$routes['web']` entry in ServiceProvider). Public, no CP auth: an email
| recipient clicking a link is not logged into the CP.
|
| GET /lh/track/click  → signed redirect that scores an email_link_clicked
|                        event before forwarding to the target URL.
|
| The route is signed (URL::signedRoute via ClickTrackingLinker::trackedUrl);
| the controller validates the signature itself and always 302-redirects,
| scoring only when the signature is valid AND the contact has consent.
|
*/

Route::get(ClickTrackingLinker::PATH, [TrackingController::class, 'click'])
    ->name(ClickTrackingLinker::ROUTE_NAME);
