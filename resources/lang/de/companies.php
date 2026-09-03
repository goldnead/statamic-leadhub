<?php

return [
    'unnamed' => 'Firma ohne Namen',
    'title' => 'Firmen',
    'singular' => 'Firma',

    // Anlegen / Bearbeiten / Löschen
    'new' => 'Neue Firma',
    'edit' => 'Firma bearbeiten',
    'created' => 'Firma angelegt.',
    'updated' => 'Firma aktualisiert.',
    'deleted' => 'Firma gelöscht.',

    // Firma an einen Kontakt hängen, vom Kontaktbildschirm aus.
    'linked' => 'Firma verknüpft.',
    'unlinked' => 'Verknüpfung entfernt.',
    'link' => 'Firma verknüpfen',
    'link_placeholder' => 'Firmen suchen…',
    'unlink' => 'Trennen',

    // Löschen wird verweigert, solange etwas an der Firma hängt — dieselbe
    // Regel wie bei den Pipeline-Stufen. Die Meldung muss sagen, was im Weg
    // ist, sonst wirkt ein verweigertes Löschen wie ein toter Knopf.
    'delete_has_contacts' => 'An dieser Firma hängen noch :count Kontakt(e). Löse die Verknüpfung zuerst.',
    'delete_has_opportunities' => 'An dieser Firma hängen noch :count Opportunity(s). Ordne sie zuerst um oder lösche sie.',

    'validation' => [
        'duplicate_name' => 'Eine Firma mit diesem Namen existiert bereits.',
        'duplicate_domain' => 'Eine Firma mit dieser Website existiert bereits (:domain).',
    ],
];
