<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta property="csp-nonce" nonce="{{ Vite::cspNonce() }}">
    <title>EmailSquire</title>
    @vite(['resources/css/app.css', 'resources/js/main.tsx'])
    @php($shellModules = app(App\Modules\ShellModules::class))
    @foreach ($shellModules->styles() as $moduleStyle)
    <link rel="stylesheet" href="{{ $moduleStyle }}">
    @endforeach
    <script type="application/json" id="emailsquire-modules" nonce="{{ Vite::cspNonce() }}">{!! json_encode($shellModules->entries(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) !!}</script>
</head>
<body>
    <div id="app"></div>
</body>
</html>
