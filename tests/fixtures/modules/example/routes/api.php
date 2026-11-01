<?php

use App\MailServer\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('ping', fn (Request $request) => response()->json([
    'module' => 'example',
    'admin' => (bool) $request->user()?->is_server_admin,
]));

// Records its audit action in the transaction that makes the change, as CLAUDE.md requires.
Route::post('notes', function (Request $request) {
    $body = $request->validate(['body' => ['required', 'string', 'max:200']])['body'];

    $id = DB::transaction(function () use ($body): int {
        $id = DB::table('example_notes')->insertGetId(['body' => $body, 'created_at' => now(), 'updated_at' => now()]);
        app(AuditLog::class)->record('example.noted', 'note', (string) $id);

        return $id;
    });

    return response()->json(['id' => $id], 201);
});
