<?php

return [
    // The empty state Support\Setup::guard() serves instead of a listing whose
    // tables are missing. Addon-wide strings, because nine screens share them —
    // hence here and not in contacts.php, tags.php or any of the other
    // per-screen files.
    'setup_required_heading' => 'This page needs its database tables, and they are not there yet.',
    'setup_required_description' => 'Run `php artisan migrate` and the page loads as usual. The reason is in the log as well.',
];
