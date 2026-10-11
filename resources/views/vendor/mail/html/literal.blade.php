@props(['value', 'heading' => false])
{{-- Text a person typed (a note, a name) or a value such as an address: escaped, with <br> for its line breaks, inside an HTML block the Markdown pass leaves as it is, so "*", "_", "#" or "[x](y)" stay plain characters. `heading` prints it as the mail's <h1> instead of a <p>. --}}
@php($html = str_replace("\n", '<br>', e(str_replace(["\r\n", "\r"], "\n", (string) $value))))
@if ($heading)
<h1>{!! $html !!}</h1>
@else
<p>{!! $html !!}</p>
@endif
