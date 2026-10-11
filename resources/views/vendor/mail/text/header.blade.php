@props(['url' => null])
{{ $slot }}@if ($url !== null): {{ $url }}@endif
