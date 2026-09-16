<?php

declare(strict_types=1);

return [

    'magic_link' => [
        'subject' => 'Your sign-in link',
        'greeting' => 'Hello :name,',
        'intro' => 'Use the link below to sign in to the zelfzorgacademie. The link works once and expires after :minutes minutes.',
        'unrequested' => 'Did you not request this link? You can ignore this email.',
    ],

    'invitation' => [
        'subject' => 'You have been invited to :organization on KOMPAZ',
        'greeting' => 'Hello :name,',
        'intro' => 'You have been invited to join :organization on KOMPAZ.',
        'instruction' => 'Use the link below to accept the invitation and sign in. The link is valid for :days days.',
    ],

    'account_deleted' => [
        'subject' => 'Your account has been deleted',
        'greeting' => 'Hello :name,',
        'intro' => 'Your zelfzorgacademie account has been deleted by an administrator. You can no longer sign in and previously sent sign-in links no longer work.',
        'contact' => 'Do you think this is a mistake? Please contact the administrator of your organization.',
    ],

    'nova_sign_in' => [
        'subject' => 'Your sign-in link for the admin panel',
        'greeting' => 'Hello :name,',
        'intro' => 'Use the link below to sign in to the KOMPAZ admin panel. The link works once and expires after :minutes minutes.',
        'unrequested' => 'Did you not request this link? You can ignore this email.',
    ],
];
