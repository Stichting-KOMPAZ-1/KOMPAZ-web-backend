<?php

declare(strict_types=1);

return [
    'actions' => [
        'cancel_button' => 'Cancel',

        'invite_user' => [
            'name' => 'Invite user',
            'confirm_button' => 'Invite',
            'message' => 'The invitation has been sent.',
            'field_name' => 'Name',
            'field_email' => 'Email address',
            'field_role' => 'Role',
            'field_organization' => 'Organization',
            'organization_help' => 'Leave empty to invite the user into your own organization.',
        ],

        'update_user' => [
            'name' => 'Edit user',
            'confirm_button' => 'Save',
            'message' => 'The user has been updated.',
            'field_name' => 'Name',
            'field_email' => 'Email address',
            'field_role' => 'Role',
            'field_organization' => 'Organization',
            'role_help' => 'Leave empty to keep the current role.',
            'organization_help' => 'Leave empty to keep the user in the same organization.',
        ],

        'archive_user' => [
            'name' => 'Archive user',
            'confirm' => 'Are you sure you want to archive this user? A user who has already signed in loses access immediately, is notified, and can be restored later. An invitation that has not been accepted yet is deleted permanently: its link stops working and it cannot be restored.',
            'confirm_button' => 'Archive',
            'message' => 'The user has been archived.',
        ],

        'purge_user' => [
            'name' => 'Delete user',
            'confirm' => 'Are you sure you want to delete this user permanently? The account is removed from the system entirely, it cannot be restored afterwards, and the email address becomes available again. A user who still has access loses it immediately and is notified.',
            'confirm_button' => 'Delete permanently',
            'message' => 'The user has been deleted permanently.',
        ],

        'restore_user' => [
            'name' => 'Restore user',
            'confirm' => 'Are you sure you want to restore this user? They regain access.',
            'confirm_button' => 'Restore',
            'message' => 'The user has been restored.',
        ],

        'resend_invitation' => [
            'name' => 'Resend invitation',
            'confirm' => 'Are you sure you want to resend the invitation? The user receives a new invitation email.',
            'confirm_button' => 'Resend',
            'message' => 'The invitation has been resent.',
        ],

        'create_organization' => [
            'name' => 'Create organization',
            'confirm_button' => 'Create',
            'message' => 'The organization has been created.',
            'field_name' => 'Name',
            'field_logo' => 'Logo',
            'logo_help' => 'Optional. An image of type :formats, up to :size. Without one, the default logo is shown.',
        ],

        'update_organization' => [
            'name' => 'Edit organization',
            'confirm_button' => 'Save',
            'message' => 'The organization has been updated.',
            'field_name' => 'Name',
        ],

        'delete_organization' => [
            'name' => 'Delete organization',
            'confirm' => 'Are you sure you want to delete this organization? This also deletes the users that belong to it.',
            'confirm_button' => 'Delete',
            'message' => 'The organization has been deleted.',
        ],

        'archive_organization' => [
            'name' => 'Archive organization',
            'confirm' => 'Are you sure you want to archive this organization? Its users will no longer be able to sign in. Nothing is lost: you can reactivate it later.',
            'confirm_button' => 'Archive',
            'message' => 'The organization has been archived.',
        ],

        'unarchive_organization' => [
            'name' => 'Reactivate organization',
            'confirm' => 'Are you sure you want to reactivate this organization? Its users will be able to sign in again with a new sign-in link.',
            'confirm_button' => 'Reactivate',
            'message' => 'The organization is active again.',
        ],

        'upload_organization_logo' => [
            'name' => 'Upload logo',
            'confirm_button' => 'Upload',
            'message' => 'The logo has been uploaded.',
            'field_logo' => 'Logo',
        ],

        'delete_organization_logo' => [
            'name' => 'Delete logo',
            'confirm' => 'Are you sure you want to delete this organization\'s logo?',
            'confirm_button' => 'Delete',
            'message' => 'The logo has been deleted.',
        ],
    ],

    'sign_in' => [
        'title' => 'Sign in to the admin panel',
        'intro' => 'Enter your email address. You will receive a sign-in link if it belongs to a platform administrator.',
        'email' => 'Email address',
        'submit' => 'Send sign-in link',
        'sent' => 'If this address belongs to a platform administrator, a sign-in link has been sent.',
        'forbidden' => 'This account does not have access to the admin panel.',
        'invitation_accepted' => 'Your invitation has been accepted. Sign in with your email address to continue.',
    ],
];
