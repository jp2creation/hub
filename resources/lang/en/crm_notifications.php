<?php

return [
    'default' => [
        'greeting' => 'Hello,',
        'action' => 'Ouvrir le HUB',
    ],
    'system_alert' => [
        'subject' => ':title',
        'greeting' => 'Hello,',
        'body' => ':body',
        'action' => 'Open',
    ],
    'invoice_reminder' => [
        'subject' => 'Invoice reminder: :invoice',
        'greeting' => 'Hello,',
        'body' => 'Invoice :invoice needs follow-up. Reminder delay: D+:days.',
        'action' => 'View invoice',
    ],
    'cash_receipt_archive' => [
        'subject' => 'Cash receipt archive',
        'body' => ':count cash receipt invoice line(s) were archived.',
    ],
    'reservation_deleted' => [
        'subject' => 'Reservation deleted: :title',
        'body' => ':actor deleted reservation :reservation (:start -> :end).',
        'action' => 'View reservations',
    ],
];
