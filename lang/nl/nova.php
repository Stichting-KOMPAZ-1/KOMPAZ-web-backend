<?php

declare(strict_types=1);

return [
    'actions' => [
        'cancel_button' => 'Annuleren',

        'invite_user' => [
            'name' => 'Gebruiker uitnodigen',
            'confirm_button' => 'Uitnodigen',
            'message' => 'De uitnodiging is verstuurd.',
            'field_name' => 'Naam',
            'field_email' => 'E-mailadres',
            'field_role' => 'Rol',
            'field_organization' => 'Organisatie',
            'organization_help' => 'Laat leeg om de gebruiker in je eigen organisatie uit te nodigen.',
        ],

        'update_user' => [
            'name' => 'Gebruiker wijzigen',
            'confirm_button' => 'Opslaan',
            'message' => 'De gebruiker is gewijzigd.',
            'field_name' => 'Naam',
            'field_email' => 'E-mailadres',
            'field_role' => 'Rol',
            'field_organization' => 'Organisatie',
            'role_help' => 'Laat leeg om de rol ongewijzigd te laten.',
            'organization_help' => 'Laat leeg om de gebruiker in dezelfde organisatie te laten.',
        ],

        'archive_user' => [
            'name' => 'Gebruiker archiveren',
            'confirm' => 'Weet je zeker dat je deze gebruiker wilt archiveren? Een gebruiker die al is ingelogd verliest direct toegang, ontvangt hierover een bericht en kan later worden hersteld. Een uitnodiging die nog niet is geaccepteerd wordt definitief verwijderd: de link werkt daarna niet meer en herstellen is niet mogelijk.',
            'confirm_button' => 'Archiveren',
            'message' => 'De gebruiker is gearchiveerd.',
        ],

        'purge_user' => [
            'name' => 'Gebruiker verwijderen',
            'confirm' => 'Weet je zeker dat je deze gebruiker definitief wilt verwijderen? Het account wordt volledig uit het systeem gehaald, herstellen is daarna niet meer mogelijk en het e-mailadres komt weer vrij. Een gebruiker die nu nog toegang heeft, verliest die direct en ontvangt hierover een bericht.',
            'confirm_button' => 'Definitief verwijderen',
            'message' => 'De gebruiker is definitief verwijderd.',
        ],

        'restore_user' => [
            'name' => 'Gebruiker herstellen',
            'confirm' => 'Weet je zeker dat je deze gebruiker wilt herstellen? De gebruiker krijgt weer toegang.',
            'confirm_button' => 'Herstellen',
            'message' => 'De gebruiker is hersteld.',
        ],

        'resend_invitation' => [
            'name' => 'Uitnodiging opnieuw versturen',
            'confirm' => 'Weet je zeker dat je de uitnodiging opnieuw wilt versturen? De gebruiker ontvangt een nieuwe uitnodigingsmail.',
            'confirm_button' => 'Opnieuw versturen',
            'message' => 'De uitnodiging is opnieuw verstuurd.',
        ],

        'create_organization' => [
            'name' => 'Organisatie aanmaken',
            'confirm_button' => 'Aanmaken',
            'message' => 'De organisatie is aangemaakt.',
            'field_name' => 'Naam',
            'field_logo' => 'Logo',
            'logo_help' => 'Optioneel. Een afbeelding van het type :formats, maximaal :size. Zonder logo wordt het standaardlogo getoond.',
        ],

        'update_organization' => [
            'name' => 'Organisatie wijzigen',
            'confirm_button' => 'Opslaan',
            'message' => 'De organisatie is gewijzigd.',
            'field_name' => 'Naam',
        ],

        'delete_organization' => [
            'name' => 'Organisatie verwijderen',
            'confirm' => 'Weet je zeker dat je deze organisatie wilt verwijderen? Dit verwijdert ook de gebruikers die eronder vallen.',
            'confirm_button' => 'Verwijderen',
            'message' => 'De organisatie is verwijderd.',
        ],

        'archive_organization' => [
            'name' => 'Organisatie archiveren',
            'confirm' => 'Weet je zeker dat je deze organisatie wilt archiveren? De gebruikers die eronder vallen kunnen daarna niet meer inloggen. Er gaat niets verloren: je kunt de organisatie later weer activeren.',
            'confirm_button' => 'Archiveren',
            'message' => 'De organisatie is gearchiveerd.',
        ],

        'unarchive_organization' => [
            'name' => 'Organisatie activeren',
            'confirm' => 'Weet je zeker dat je deze organisatie weer wilt activeren? De gebruikers die eronder vallen kunnen daarna weer inloggen via een nieuwe login-link.',
            'confirm_button' => 'Activeren',
            'message' => 'De organisatie is weer actief.',
        ],

        'upload_organization_logo' => [
            'name' => 'Logo uploaden',
            'confirm_button' => 'Uploaden',
            'message' => 'Het logo is geüpload.',
            'field_logo' => 'Logo',
        ],

        'delete_organization_logo' => [
            'name' => 'Logo verwijderen',
            'confirm' => 'Weet je zeker dat je het logo van deze organisatie wilt verwijderen?',
            'confirm_button' => 'Verwijderen',
            'message' => 'Het logo is verwijderd.',
        ],
    ],

    'sign_in' => [
        'title' => 'Inloggen op het beheerpaneel',
        'intro' => 'Vul je e-mailadres in. Je ontvangt een login-link als het adres bij een platformbeheerder hoort.',
        'email' => 'E-mailadres',
        'submit' => 'Stuur login-link',
        'sent' => 'Als dit adres bij een platformbeheerder hoort, is er een login-link verstuurd.',
        'forbidden' => 'Dit account heeft geen toegang tot het beheerpaneel.',
        // Shown after an invitation link was accepted by somebody the panel does not admit.
        // A platform administrator never reads it: their link signs them in and lands them on
        // the dashboard, so this says what happened and why they are looking at a login form.
        'invitation_accepted' => 'Je uitnodiging is geaccepteerd en je account is nu actief. Dit beheerpaneel is alleen voor platformbeheerders.',
    ],
];
