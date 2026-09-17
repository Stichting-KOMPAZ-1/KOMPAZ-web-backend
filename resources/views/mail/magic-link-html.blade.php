@include('mail.action-card', [
    'title' => __('mail.magic_link.subject'),
    'preheader' => __('mail.magic_link.intro', ['minutes' => $minutes]),
    'heading' => __('mail.magic_link.greeting', ['name' => $name]),
    'body' => __('mail.magic_link.intro', ['minutes' => $minutes]),
    'action' => __('mail.magic_link.action'),
    'validity' => __('mail.magic_link.unrequested'),
    'link' => $link,
])
