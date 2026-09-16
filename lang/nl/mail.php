<?php

declare(strict_types=1);

/*
| The wording of the outbound emails. Dutch is the product's language and the fallback for any
| locale without a translation of its own; adding a language means adding one directory, and
| nothing in the mailables changes.
*/

return [

    'magic_link' => [
        // Fixed by the ticket: exactly "Je login-link".
        'subject' => 'Je login-link',
        'greeting' => 'Hallo :name,',
        'intro' => 'Gebruik onderstaande link om in te loggen bij de zelfzorgacademie. De link werkt één keer en verloopt na :minutes minuten.',
        'unrequested' => 'Heb je deze link niet aangevraagd? Dan kun je deze e-mail negeren.',
    ],

    'invitation' => [
        'subject' => 'Je bent uitgenodigd voor :organization op KOMPAZ',
        'greeting' => 'Hallo :name,',
        'intro' => 'Je bent uitgenodigd om deel te nemen aan :organization op KOMPAZ.',
        'instruction' => 'Gebruik onderstaande link om de uitnodiging te accepteren en in te loggen. De link is :days dagen geldig.',
    ],

    'account_deleted' => [
        'subject' => 'Jouw account is verwijderd',
        'greeting' => 'Hallo :name,',
        'intro' => 'Je account voor de zelfzorgacademie is verwijderd door een beheerder. Je kunt niet meer inloggen en eerder verstuurde inloglinks werken niet meer.',
        'contact' => 'Denk je dat dit niet klopt? Neem dan contact op met de beheerder van je organisatie.',
    ],

    'nova_sign_in' => [
        'subject' => 'Je login-link voor het beheerpaneel',
        'greeting' => 'Hallo :name,',
        'intro' => 'Gebruik onderstaande link om in te loggen op het KOMPAZ-beheerpaneel. De link werkt één keer en verloopt na :minutes minuten.',
        'unrequested' => 'Heb je deze link niet aangevraagd? Dan kun je deze e-mail negeren.',
    ],
];
