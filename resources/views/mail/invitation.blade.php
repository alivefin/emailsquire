<x-mail::message>
# {{ __('mail.invitation.subject') }}

<x-mail::literal :value="$greeting" />

<x-mail::literal :value="$intro" />

<x-mail::literal :value="$access" />

<x-mail::literal :value="$roleLine" />

@if ($note !== null)
<x-mail::literal :value="$noteHeading" />

<x-mail::literal :value="$note" />

@endif
<x-mail::button :url="$url">
{{ __('mail.invitation.action') }}
</x-mail::button>

{{ __('mail.invitation.expires', ['hours' => $hours]) }}

{{ __('mail.invitation.not_you') }}

{{ __('mail.invitation.link_text') }}<br>
{{ $url }}
</x-mail::message>
