<x-mail::message>
# {{ $heading }}

{{ $what }}

{{ __('mail.security.when', ['time' => $time]) }}

{{ $from }}

{{ __('mail.security.not_you') }}
</x-mail::message>
