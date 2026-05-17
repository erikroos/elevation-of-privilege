<?php
// Kopieer dit bestand naar config.php en vul de waardes in.
// config.php hoort NIET in version control (zie .gitignore).

return [
    'db' => [
        'host'     => 'localhost',
        'name'     => 'eop',
        'user'     => 'eop_user',
        'pass'     => 'CHANGE_ME',
        'charset'  => 'utf8mb4',
    ],

    // Gebruikt voor: facilitator-tokens, CSRF, signed cookies.
    // Genereer met: php -r "echo bin2hex(random_bytes(32));"
    'app_secret' => 'CHANGE_ME_TO_A_LONG_RANDOM_STRING',

    // Pad waar geüploade diagrammen worden opgeslagen.
    // Relatief t.o.v. public/ — moet schrijfbaar zijn voor de webserver.
    'upload_dir' => __DIR__ . '/../public/uploads/diagrams',

    // Max upload size in bytes (10 MB).
    'upload_max_bytes' => 10 * 1024 * 1024,

    // Welke MIME types zijn toegestaan voor diagram-uploads.
    'upload_allowed_mimes' => [
        'image/png',
        'image/jpeg',
        'image/svg+xml',
        'application/pdf',
    ],

    // Hoe lang een long-poll request maximaal blijft hangen (seconden).
    'poll_max_wait' => 10,

    // Productie: zet op false zodat fouten niet naar de gebruiker lekken.
    'debug' => true,
];
