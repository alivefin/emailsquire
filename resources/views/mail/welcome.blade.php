<x-mail::message>
<x-mail::literal :value="$heading" heading />

<x-mail::literal :value="$intro" />

@if ($webmailUrl !== null)
{{ __('mail.welcome.webmail') }}

<x-mail::button :url="$webmailUrl">
{{ __('mail.welcome.webmail_action') }}
</x-mail::button>

@endif
## {{ __('mail.welcome.settings') }}

<x-mail::literal :value="$username" />

<x-mail::literal :value="$imap" />

<x-mail::literal :value="$smtp" />

{{ __('mail.welcome.smtp_auth') }}

**{{ __('mail.welcome.password') }}**

{{ __('mail.welcome.not_you') }}
</x-mail::message>
