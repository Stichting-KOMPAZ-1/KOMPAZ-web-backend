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
        'action' => 'Inloggen op de zelfzorgacademie',
        'unrequested' => 'Heb je deze link niet aangevraagd? Dan kun je deze e-mail negeren.',
    ],

    'invitation' => [
        // Fixed by the ticket: exactly "Uitnodiging om deel te nemen aan het ZelfZorg-platform",
        // naming no organization, so every invitation arrives under one recognisable subject.
        'subject' => 'Uitnodiging om deel te nemen aan het ZelfZorg-platform',
        'greeting' => 'Hi :name,',
        'intro' => 'Je bent uitgenodigd voor de ZelfZorgacademie-omgeving van :organization.',
        'action' => 'Accepteer uitnodiging',
        'validity' => 'Deze link is geldig tot :expiresAt',
    ],

    'account_deleted' => [
        'subject' => 'Jouw account is verwijderd',
        'greeting' => 'Beste gebruiker,',
        'intro' => 'We hebben je account op het ZelfZorg platform verwijderd zoals gevraagd. Hierdoor kun je niet meer inloggen of inhoud bewerken.',
        'contact' => 'Wil je de toegang toch weer herstellen? Neem dan contact op via [e-mailadres / telefoonnummer].',
        'signoff' => 'Groet, Stichting KOMPAZ',
    ],

    'nova_sign_in' => [
        'subject' => 'Je login-link voor het beheerpaneel',
        'greeting' => 'Hi :name',
        'intro' => 'Klik op de knop hieronder om in te loggen. De link is :minutes minuten geldig.',
        'action' => 'Inloggen op het ZelfZorg platform',
    ],
];
