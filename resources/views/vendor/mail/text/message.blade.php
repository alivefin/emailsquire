@props(['linkHeader' => true])
{{-- Laravel's message, with `link-header`: false for mail to people without a console account (the welcome email), whose header names the app without APP_URL. --}}
<x-mail::layout>
    {{-- Header --}}
    <x-slot:header>
        <x-mail::header :url="$linkHeader ? config('app.url') : null">
            {{ config('app.name') }}
        </x-mail::header>
    </x-slot:header>

    {{-- Body --}}
    {{ $slot }}

    {{-- Subcopy --}}
    @isset($subcopy)
        <x-slot:subcopy>
            <x-mail::subcopy>
                {{ $subcopy }}
            </x-mail::subcopy>
        </x-slot:subcopy>
    @endisset

    {{-- Footer --}}
    <x-slot:footer>
        <x-mail::footer>
            © {{ date('Y') }} {{ config('app.name') }}. @lang('All rights reserved.')
        </x-mail::footer>
    </x-slot:footer>
</x-mail::layout>
