<?php

return [
    'title' => 'Eigene Felder',
    'nav' => 'Eigene Felder',
    'description' => 'Werte, die du selbst über deine Kontakte festhältst — Stimmlage, Chorgröße, Bundesland. Ein Tag kann nur ja oder nein sagen; ein Feld hält einen Wert, auf den ein Segment vergleichen kann.',
    'label' => 'Bezeichnung',
    'handle' => 'Kürzel',
    'handle_hint' => 'Unter diesem Kürzel wird der Wert gespeichert und in Segmenten angesprochen. Es lässt sich später nicht ändern — jeder bereits geschriebene Wert hängt daran.',
    'type' => 'Typ',
    'in_use' => 'Kontakte mit Wert',
    'instructions' => 'Hinweis',
    'options' => 'Auswahlmöglichkeiten',
    'add' => 'Feld anlegen',
    'types' => [
        'text' => 'Text',
        'number' => 'Zahl',
        'select' => 'Auswahl',
        'date' => 'Datum',
        'boolean' => 'Ja/Nein',
    ],
    'delete_confirm' => 'Die Definition wird gelöscht, die bereits erfassten Werte bleiben stehen. Sie sind danach nicht mehr lesbar, bis jemand dasselbe Kürzel wieder anlegt. Gelöschte Werte wären nicht wiederherstellbar — deshalb werden sie hier nicht angerührt.',
    'empty' => 'Noch kein eigenes Feld angelegt.',
    'flashes' => [
        'created' => 'Feld angelegt.',
        'updated' => 'Feld gespeichert.',
        'deleted' => 'Feld gelöscht. Die erfassten Werte stehen noch.',
    ],
];
