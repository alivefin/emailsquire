@props(['url'])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
{{-- The app name as text. Laravel's stock header loads a logo from laravel.com; mail here has no external images. --}}
{!! $slot !!}
</a>
</td>
</tr>
