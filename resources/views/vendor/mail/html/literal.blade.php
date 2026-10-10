@props(['value'])
{{-- Text a person typed (a note, a name): escaped, on one line with <br> for its line breaks, inside an HTML block the Markdown pass leaves as it is, so "*", "#" or "[x](y)" stay plain characters. --}}
<p>{!! str_replace("\n", '<br>', e(str_replace(["\r\n", "\r"], "\n", (string) $value))) !!}</p>
