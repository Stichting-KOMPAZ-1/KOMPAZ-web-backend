{{ __('mail.magic_link.greeting', ['name' => $name]) }}

{{ __('mail.magic_link.intro', ['minutes' => $minutes]) }}

{{ $link }}

{{ __('mail.magic_link.unrequested') }}
