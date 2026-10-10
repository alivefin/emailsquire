<x-mail::message>
# {{ __('mail.reset.subject') }}

{{ __('mail.reset.intro') }}

<x-mail::button :url="$url">
{{ __('mail.reset.action') }}
</x-mail::button>

{{ __('mail.reset.expires', ['minutes' => $minutes]) }}

{{ __('mail.reset.not_you') }}

{{ __('mail.reset.link_text') }}<br>
{{ $url }}
</x-mail::message>
