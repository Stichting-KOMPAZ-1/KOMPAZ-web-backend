@include('mail.action-card', [
    'title' => __('mail.invitation.subject'),
    'preheader' => __('mail.invitation.intro', ['organization' => $organization]),
    'heading' => __('mail.invitation.greeting', ['name' => $name]),
    'body' => __('mail.invitation.intro', ['organization' => $organization]),
    'action' => __('mail.invitation.action'),
    'validity' => __('mail.invitation.validity', ['expiresAt' => $expiresAt]),
    'link' => $link,
])
