<?php

return [
    'default' => [
        'greeting' => 'Bonjour,',
        'action' => 'Ouvrir le HUB',
    ],
    'system_alert' => [
        'subject' => ':title',
        'greeting' => 'Bonjour,',
        'body' => ':body',
        'action' => 'Ouvrir',
    ],
    'invoice_reminder' => [
        'subject' => 'Rappel facture : :invoice',
        'greeting' => 'Bonjour,',
        'body' => 'La facture :invoice est a suivre. Delai de rappel : J+:days.',
        'action' => 'Voir la facture',
    ],
    'cash_receipt_archive' => [
        'subject' => 'Archivage des factures de caisse',
        'body' => ':count facture(s) de caisse ont ete archivees.',
    ],
    'reservation_deleted' => [
        'subject' => 'Reservation supprimee : :title',
        'body' => ':actor a supprime la reservation :reservation (:start -> :end).',
        'action' => 'Voir les reservations',
    ],
];
