@props(['url' => null])
<tr>
<td class="header">
@if ($url !== null)
<a href="{{ $url }}" style="display: inline-block;">
{{-- The app name as text. Laravel's stock header loads a logo from laravel.com; mail here has no external images. --}}
{!! $slot !!}
</a>
@else
{{-- No link: for mail to people without a console account (the welcome email). Styled like `.header a`. --}}
<span style="display: inline-block; color: #18181b; font-size: 19px; font-weight: bold;">
{!! $slot !!}
</span>
@endif
</td>
</tr>
