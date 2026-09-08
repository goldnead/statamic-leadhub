<?php

return [
    // Der Leerzustand, den Support\Setup::guard() anstelle einer Liste
    // ausliefert, deren Tabellen fehlen. Adressweite Strings, weil neun
    // Screens sie teilen — deshalb hier und nicht in contacts.php, tags.php
    // oder einer der anderen bildschirmeigenen Dateien.
    'setup_required_heading' => 'Diese Seite braucht ihre Datenbanktabellen, und die gibt es noch nicht.',
    'setup_required_description' => 'Führe `php artisan migrate` aus, danach lädt die Seite normal. Der Grund steht auch im Log.',
];
