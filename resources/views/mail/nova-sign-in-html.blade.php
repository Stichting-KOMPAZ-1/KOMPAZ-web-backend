@include('mail.action-card', [
    'title' => __('mail.nova_sign_in.subject'),
    'preheader' => __('mail.nova_sign_in.intro', ['minutes' => $minutes]),
    'heading' => __('mail.nova_sign_in.greeting', ['name' => $name]),
    'body' => __('mail.nova_sign_in.intro', ['minutes' => $minutes]),
    'action' => __('mail.nova_sign_in.action'),
    'validity' => '',
    'link' => $link,
])
