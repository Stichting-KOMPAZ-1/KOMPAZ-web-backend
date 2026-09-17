<?php

declare(strict_types=1);

return [

    'magic_link' => [
        'subject' => 'Your sign-in link',
        'greeting' => 'Hello :name,',
        'intro' => 'Use the link below to sign in to the zelfzorgacademie. The link works once and expires after :minutes minutes.',
        'action' => 'Sign in to the zelfzorgacademie',
        'unrequested' => 'Did you not request this link? You can ignore this email.',
    ],

    'invitation' => [
        'subject' => 'You have been invited to :organization on KOMPAZ',
        'greeting' => 'Hi :name,',
        'intro' => 'You have been invited to the ZelfZorgacademie environment of :organization.',
        'action' => 'Accept invitation',
        'validity' => 'This link is valid until :expiresAt',
    ],

    'account_deleted' => [
        'subject' => 'Your account has been deleted',
        'greeting' => 'Dear user,',
        'intro' => 'We have deleted your account on the ZelfZorg platform as requested. You can no longer sign in or edit content.',
        'contact' => 'Would you like your access restored after all? Please get in touch at [e-mailadres / telefoonnummer].',
        'signoff' => 'Kind regards, Stichting KOMPAZ',
    ],

    'nova_sign_in' => [
        'subject' => 'Your sign-in link for the admin panel',
        'greeting' => 'Hi :name',
        'intro' => 'Click the button below to sign in. The link is valid for :minutes minutes.',
        'action' => 'Sign in to the ZelfZorg platform',
    ],
];
