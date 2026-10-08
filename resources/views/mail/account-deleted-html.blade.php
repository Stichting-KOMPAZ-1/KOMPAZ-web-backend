@include('mail.action-card', [
    'title' => __('mail.account_deleted.subject'),
    'preheader' => __('mail.account_deleted.intro'),
    'heading' => __('mail.account_deleted.subject'),
    'body' => [
        __('mail.account_deleted.greeting'),
        __('mail.account_deleted.intro'),
        __('mail.account_deleted.contact'),
    ],
])
