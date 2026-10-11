<?php

use App\Models\HelperTask;
use EmailSquire\Helpers\Dkim\DkimHelper;
use Illuminate\Support\Facades\DB;
use Tests\Support\GuideFixture;
use Tests\Support\TempTree;

/** Inserts a dkim task the way the web app would, with any column overridden. */
function dkimTask(string $type, mixed $payload, array $over = []): HelperTask
{
    $task = new HelperTask;
    $task->forceFill([
        'type' => $type,
        'address' => null,
        'domain_name' => is_array($payload) && is_string($payload['domain'] ?? null) ? $payload['domain'] : null,
        'status' => 'pending',
        'run_after' => now()->subMinute(),
        ...$over,
    ]);
    $task->setRawAttributes([...$task->getAttributes(), 'payload' => is_string($payload) ? $payload : json_encode($payload)]);
    $task->save();

    return $task;
}

/** A private key PEM, made once per process: RSA generation is slow. */
function dkimTestPem(int $which = 0): string
{
    static $pems = [];

    if (! isset($pems[$which])) {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);
        $pems[$which] = $pem;
    }

    return $pems[$which];
}

/** The DKIM TXT for a private key PEM, derived here independently of the helper. */
function dkimExpectedTxt(string $pem): string
{
    $public = openssl_pkey_get_details(openssl_pkey_get_private($pem))['key'];
    $body = preg_replace('/-----[A-Z ]+-----|\s+/', '', $public);

    return "v=DKIM1; k=rsa; p={$body}";
}

/** Everything under the temp tree except the lock: what a refused task must leave alone. */
function dkimTree(string $tmp): array
{
    $tree = TempTree::snapshot($tmp);
    clearstatcache();
    foreach ($tree as $path => $entry) {
        if (! str_starts_with($entry, 'link:') && is_file("{$tmp}/{$path}")) {
            $tree[$path] .= '|'.decoct(fileperms("{$tmp}/{$path}") & 0o777).'|'.fileinode("{$tmp}/{$path}");
        }
    }

    return $tree;
}

beforeEach(function () {
    GuideFixture::load('trixie');
    $guide = (string) config('emailsquire.guide_schema');
    DB::table("{$guide}.virtual_domains")->insert([['id' => 11, 'name' => 'example.net'], ['id' => 12, 'name' => 'Mixed.example'], ['id' => 13, 'name' => 'xn--bcher-kva.example']]);

    // realpath: on macOS the temp dir sits under /var, which is a symlink to /private/var.
    $this->tmp = realpath(TempTree::create());
    $this->dkim = "{$this->tmp}/dkim";
    $this->rspamd = "{$this->tmp}/rspamd";
    $this->map = "{$this->rspamd}/dkim_selectors.map";
    mkdir($this->dkim, 0700);
    mkdir($this->rspamd, 0755);
    mkdir("{$this->tmp}/outside", 0700);
    $this->mapText = "# DKIM selectors, one per domain\nexample.net 2025121401\n\nexample.org 2025121401\n  # keep this indented comment\nother.example\tsel1 # trailing note\n";
    file_put_contents($this->map, $this->mapText);
    chmod($this->map, 0664);
    $this->log = [];
    $this->now = gmmktime(12, 0, 0, 10, 11, 2026);

    $this->helper = fn () => new DkimHelper(
        DB::connection()->getPdo(),
        (string) config('emailsquire.guide_schema'),
        DB::connection()->getDatabaseName(),
        $this->dkim,
        $this->map,
        fn (): int => $this->now,
        function (string $line): void {
            $this->log[] = $line;
            // Test seam: runs just before the helper writes the task's outcome.
            if (isset($this->onLog)) {
                ($this->onLog)();
            }
        },
    );
    $this->key = fn (string $name, int $which = 0) => file_put_contents("{$this->dkim}/{$name}", dkimTestPem($which));
});

afterEach(function () {
    @chmod($this->rspamd, 0755);
    @chmod($this->dkim, 0700);
    TempTree::remove($this->tmp);
});

describe('generate', function () {
    it('writes a 0600 PEM whose public half is the result txt, and leaves the map alone', function () {
        $task = dkimTask('dkim.generate', ['domain' => 'example.org']);

        expect(($this->helper)()->run())->toBe(['done' => 1, 'failed' => 0, 'retried' => 0]);

        $task->refresh();
        $path = "{$this->dkim}/example.org.es20261011.key";
        $pem = (string) file_get_contents($path);
        $private = openssl_pkey_get_private($pem);
        expect($task->status)->toBe('done')
            ->and($task->last_error)->toBeNull()
            ->and($task->result)->toBe(['selector' => 'es20261011', 'txt' => dkimExpectedTxt($pem)])
            ->and($task->result['txt'])->toStartWith('v=DKIM1; k=rsa; p=MIIBIjAN')
            ->and($pem)->toStartWith('-----BEGIN PRIVATE KEY-----')
            ->and($private)->not->toBeFalse()
            ->and(openssl_pkey_get_details($private))->toMatchArray(['bits' => 2048, 'type' => OPENSSL_KEYTYPE_RSA])
            ->and(decoct(fileperms($path) & 0o777))->toBe('600')
            ->and(scandir($this->dkim))->toBe(['.', '..', 'example.org.es20261011.key'])
            ->and(file_get_contents($this->map))->toBe($this->mapText)
            ->and($this->log)->toBe(["task {$task->id} done: generated example.org es20261011"]);
    });

    it('names a second key on the same day with a letter, and starts over the next day', function () {
        ($this->key)('example.org.es20261011.key');
        $b = dkimTask('dkim.generate', ['domain' => 'example.org']);
        $c = dkimTask('dkim.generate', ['domain' => 'example.org']);

        ($this->helper)()->run();
        $this->now += 86400;
        $next = dkimTask('dkim.generate', ['domain' => 'example.org']);
        ($this->helper)()->run();

        expect($b->refresh()->result['selector'])->toBe('es20261011b')
            ->and($c->refresh()->result['selector'])->toBe('es20261011c')
            ->and($next->refresh()->result['selector'])->toBe('es20261012')
            ->and("{$this->dkim}/example.org.es20261011c.key")->toBeFile();
    });

    it('skips the selector the map already names, even without its key file', function () {
        file_put_contents($this->map, "example.org es20261011\n");
        $task = dkimTask('dkim.generate', ['domain' => 'example.org']);

        ($this->helper)()->run();

        expect($task->refresh()->result['selector'])->toBe('es20261011b');
    });

    it('keys selectors per domain', function () {
        ($this->key)('example.net.es20261011.key');
        $task = dkimTask('dkim.generate', ['domain' => 'example.org']);

        ($this->helper)()->run();

        expect($task->refresh()->result['selector'])->toBe('es20261011');
    });

    it('fails when every selector of the day is taken', function () {
        ($this->key)('example.org.es20261011.key');
        foreach (range('b', 'z') as $letter) {
            ($this->key)("example.org.es20261011{$letter}.key");
        }
        $before = dkimTree($this->tmp);
        $task = dkimTask('dkim.generate', ['domain' => 'example.org']);

        ($this->helper)()->run();

        expect($task->refresh()->status)->toBe('failed')
            ->and($task->last_error)->toBe('generate refused: every selector for today is taken')
            ->and(dkimTree($this->tmp))->toBe($before);
    });
});

describe('activate', function () {
    it('rewrites only its domain line, by rename, keeping the mode', function () {
        ($this->key)('example.org.es20261011.key');
        $inode = fileinode($this->map);
        $task = dkimTask('dkim.activate', ['domain' => 'example.org', 'selector' => 'es20261011']);

        expect(($this->helper)()->run())->toBe(['done' => 1, 'failed' => 0, 'retried' => 0]);

        clearstatcache();
        expect($task->refresh()->status)->toBe('done')
            ->and($task->result)->toBe(['selector' => 'es20261011', 'txt' => dkimExpectedTxt(dkimTestPem())])
            ->and(file_get_contents($this->map))->toBe(str_replace("example.org 2025121401\n", "example.org es20261011\n", $this->mapText))
            ->and(fileinode($this->map))->not->toBe($inode)
            ->and(decoct(fileperms($this->map) & 0o777))->toBe('664')
            ->and(scandir($this->rspamd))->toBe(['.', '..', 'dkim_selectors.map']);
    });

    it('keeps CRLF line endings and a last line without a newline', function () {
        file_put_contents($this->map, "# c\r\nexample.org old\r\nexample.net x");
        ($this->key)('example.org.es20261011.key');
        dkimTask('dkim.activate', ['domain' => 'example.org', 'selector' => 'es20261011']);

        ($this->helper)()->run();

        expect(file_get_contents($this->map))->toBe("# c\r\nexample.org es20261011\r\nexample.net x");
    });

    it('appends with the map\'s own CRLF line ending', function () {
        file_put_contents($this->map, "# c\r\nexample.net x");
        ($this->key)('example.org.es20261011.key');
        dkimTask('dkim.activate', ['domain' => 'example.org', 'selector' => 'es20261011']);

        ($this->helper)()->run();

        expect(file_get_contents($this->map))->toBe("# c\r\nexample.net x\r\nexample.org es20261011\r\n");
    });

    it('rewrites the domain line plainly, dropping its indent and trailing comment', function () {
        file_put_contents($this->map, "a.example x\n  EXAMPLE.org\told # rotated in 2025\nb.example y\n");
        ($this->key)('example.org.es20261011.key');
        dkimTask('dkim.activate', ['domain' => 'example.org', 'selector' => 'es20261011']);

        ($this->helper)()->run();

        expect(file_get_contents($this->map))->toBe("a.example x\nexample.org es20261011\nb.example y\n");
    });

    it('appends a line for a domain the map does not name yet', function () {
        file_put_contents($this->map, 'example.net x');
        ($this->key)('example.org.es20261011.key');
        dkimTask('dkim.activate', ['domain' => 'example.org', 'selector' => 'es20261011']);

        ($this->helper)()->run();

        expect(file_get_contents($this->map))->toBe("example.net x\nexample.org es20261011\n");
    });

    it('creates a missing map with mode 0644', function () {
        unlink($this->map);
        ($this->key)('example.org.es20261011.key');
        dkimTask('dkim.activate', ['domain' => 'example.org', 'selector' => 'es20261011']);

        ($this->helper)()->run();

        expect(file_get_contents($this->map))->toBe("example.org es20261011\n")
            ->and(decoct(fileperms($this->map) & 0o777))->toBe('644');
    });

    it('leaves the map untouched when the selector is already active', function () {
        file_put_contents($this->map, "example.org es20261011\n");
        $inode = fileinode($this->map);
        ($this->key)('example.org.es20261011.key');
        $task = dkimTask('dkim.activate', ['domain' => 'example.org', 'selector' => 'es20261011']);

        ($this->helper)()->run();

        clearstatcache();
        expect($task->refresh()->status)->toBe('done')
            ->and($task->note)->toBe('example.org es20261011 is already active')
            ->and(fileinode($this->map))->toBe($inode);
    });

    it('leaves no partial file behind when the map cannot be replaced, and retries', function () {
        if (posix_geteuid() === 0) {
            $this->markTestSkipped('root ignores directory permissions');
        }
        ($this->key)('example.org.es20261011.key');
        chmod($this->rspamd, 0555);
        $task = dkimTask('dkim.activate', ['domain' => 'example.org', 'selector' => 'es20261011']);

        expect(($this->helper)()->run())->toBe(['done' => 0, 'failed' => 0, 'retried' => 1]);

        expect($task->refresh()->status)->toBe('pending')
            ->and($task->attempts)->toBe(1)
            ->and($task->last_error)->toStartWith('cannot write the selector map')
            ->and($task->result)->toBeNull()
            ->and(file_get_contents($this->map))->toBe($this->mapText)
            ->and(scandir($this->rspamd))->toBe(['.', '..', 'dkim_selectors.map']);
    });

    it('refuses a key that is missing or not a private key, and a map naming the domain twice', function (string $case) {
        match ($case) {
            'missing key' => null,
            'junk key' => file_put_contents("{$this->dkim}/example.org.es20261011.key", "-----BEGIN PRIVATE KEY-----\nnot a key\n-----END PRIVATE KEY-----\n"),
            'duplicate line' => [($this->key)('example.org.es20261011.key'), file_put_contents($this->map, $this->mapText."example.org other\n")],
        };
        $before = dkimTree($this->tmp);
        $task = dkimTask('dkim.activate', ['domain' => 'example.org', 'selector' => 'es20261011']);

        expect(($this->helper)()->run())->toBe(['done' => 0, 'failed' => 1, 'retried' => 0]);

        expect($task->refresh()->status)->toBe('failed')
            ->and($task->last_error)->toBe(match ($case) {
                'missing key' => 'activate refused: no key file example.org.es20261011.key',
                'junk key' => 'activate refused: example.org.es20261011.key is not an RSA private key',
                'duplicate line' => 'refused: the selector map names example.org more than once',
            })
            ->and(dkimTree($this->tmp))->toBe($before);
    })->with(['missing key', 'junk key', 'duplicate line']);
});

describe('retire', function () {
    it('deletes a key that is not active', function () {
        ($this->key)('example.org.es20261011.key');
        ($this->key)('example.org.es20261011b.key');
        file_put_contents($this->map, "example.org es20261011b\n");
        $task = dkimTask('dkim.retire', ['domain' => 'example.org', 'selector' => 'es20261011']);

        ($this->helper)()->run();

        expect($task->refresh()->status)->toBe('done')
            ->and($task->result)->toBe(['selector' => 'es20261011'])
            ->and(scandir($this->dkim))->toBe(['.', '..', 'example.org.es20261011b.key'])
            ->and(file_get_contents($this->map))->toBe("example.org es20261011b\n");
    });

    it('marks a retire of a key that is already gone done', function () {
        $task = dkimTask('dkim.retire', ['domain' => 'example.org', 'selector' => 'es20261011']);

        ($this->helper)()->run();

        expect($task->refresh()->status)->toBe('done')
            ->and($task->note)->toBe('no key file example.org.es20261011.key to retire')
            ->and($task->result)->toBe(['selector' => 'es20261011']);
    });

    it('refuses the active key', function () {
        ($this->key)('example.org.es20261011.key');
        file_put_contents($this->map, "example.org ES20261011\n");
        $before = dkimTree($this->tmp);
        $task = dkimTask('dkim.retire', ['domain' => 'example.org', 'selector' => 'es20261011']);

        ($this->helper)()->run();

        expect($task->refresh()->status)->toBe('failed')
            ->and($task->last_error)->toBe('retire refused: es20261011 is the active selector for example.org')
            ->and(dkimTree($this->tmp))->toBe($before);
    });
});

describe('inspect', function () {
    it('derives each key txt and reads the active selector, including keys it did not make', function () {
        ($this->key)('example.org.2025121401.key', 0);
        ($this->key)('example.org.es20261011.key', 1);
        ($this->key)('example.net.2025121401.key', 1);
        ($this->key)('example.org.uk.sel.key', 1);
        file_put_contents("{$this->dkim}/example.org.ed.key", base64_encode(random_bytes(32)));
        file_put_contents("{$this->dkim}/.example.org.tmp.key", 'x');
        $before = dkimTree($this->tmp);
        $task = dkimTask('dkim.inspect', ['domain' => 'example.org']);

        ($this->helper)()->run();

        expect($task->refresh()->status)->toBe('done')
            ->and($task->result)->toBe([
                'active_selector' => '2025121401',
                'keys' => [
                    ['selector' => '2025121401', 'txt' => dkimExpectedTxt(dkimTestPem(0))],
                    ['selector' => 'ed', 'txt' => null],
                    ['selector' => 'es20261011', 'txt' => dkimExpectedTxt(dkimTestPem(1))],
                ],
            ])
            ->and(dkimTree($this->tmp))->toBe($before);
    });

    it('reports no active selector and no keys for a domain the map and directory lack', function () {
        $task = dkimTask('dkim.inspect', ['domain' => 'xn--bcher-kva.example']);

        ($this->helper)()->run();

        expect($task->refresh()->result)->toBe(['active_selector' => null, 'keys' => []]);
    });

    it('reports rspamd\'s fallback key as active when the map has no line for the domain', function () {
        // rspamd's dkim_signing signs with its default selector `dkim` (try_fallback) when selector_map has
        // no line for the domain, so <domain>.dkim.key is the key in use.
        ($this->key)('xn--bcher-kva.example.dkim.key', 0);
        ($this->key)('xn--bcher-kva.example.es20261011.key', 1);
        $task = dkimTask('dkim.inspect', ['domain' => 'xn--bcher-kva.example']);

        ($this->helper)()->run();

        expect($task->refresh()->result)->toBe([
            'active_selector' => 'dkim',
            'keys' => [
                ['selector' => 'dkim', 'txt' => dkimExpectedTxt(dkimTestPem(0))],
                ['selector' => 'es20261011', 'txt' => dkimExpectedTxt(dkimTestPem(1))],
            ],
        ])->and($task->note)->toContain('fallback');
    });

    it('keeps the map\'s selector active over a fallback key file', function () {
        ($this->key)('example.org.dkim.key', 0);
        ($this->key)('example.org.2025121401.key', 1);
        $task = dkimTask('dkim.inspect', ['domain' => 'example.org']);

        ($this->helper)()->run();

        expect($task->refresh()->result['active_selector'])->toBe('2025121401');
    });

    it('works without a map', function () {
        unlink($this->map);
        $task = dkimTask('dkim.inspect', ['domain' => 'example.org']);

        ($this->helper)()->run();

        expect($task->refresh()->result)->toBe(['active_selector' => null, 'keys' => []]);
    });
});

describe('a missing selector map', function () {
    it('retries every task type while the map\'s directory is missing, never assuming no key is active', function (string $type) {
        ($this->key)('example.org.es20261011.key');
        unlink($this->map);
        rmdir($this->rspamd);
        $before = dkimTree($this->tmp);
        $task = dkimTask($type, ['domain' => 'example.org', 'selector' => 'es20261011']);

        expect(($this->helper)()->run())->toBe(['done' => 0, 'failed' => 0, 'retried' => 1]);

        expect($task->refresh()->status)->toBe('pending')
            ->and($task->last_error)->toBe("the selector map's directory is missing")
            ->and($task->result)->toBeNull()
            ->and(dkimTree($this->tmp))->toBe($before);
    })->with(['dkim.retire', 'dkim.inspect', 'dkim.generate', 'dkim.activate']);

    it('treats a missing map file in an existing directory as no active selector', function () {
        ($this->key)('example.org.es20261011.key');
        unlink($this->map);
        $retire = dkimTask('dkim.retire', ['domain' => 'example.org', 'selector' => 'es20261011']);

        ($this->helper)()->run();

        expect($retire->refresh()->status)->toBe('done')
            ->and("{$this->dkim}/example.org.es20261011.key")->not->toBeFile();
    });
});

describe('oversized files', function () {
    it('refuses a key file or map larger than its cap, without reading it', function (string $what) {
        $what === 'key'
            ? file_put_contents("{$this->dkim}/example.org.es20261011.key", dkimTestPem().str_repeat("\n", 65537 - strlen(dkimTestPem())))
            : [($this->key)('example.org.es20261011.key'), file_put_contents($this->map, $this->mapText.str_repeat('#', 1048577 - strlen($this->mapText)))];
        $before = dkimTree($this->tmp);
        $task = dkimTask('dkim.activate', ['domain' => 'example.org', 'selector' => 'es20261011']);

        ($this->helper)()->run();

        expect($task->refresh()->status)->toBe('failed')
            ->and($task->last_error)->toBe($what === 'key'
                ? 'refused: the key file example.org.es20261011.key is larger than 65536 bytes'
                : 'refused: the selector map is larger than 1048576 bytes')
            ->and(dkimTree($this->tmp))->toBe($before);
    })->with(['key', 'map']);

    it('accepts files at exactly the cap', function () {
        file_put_contents("{$this->dkim}/example.org.es20261011.key", dkimTestPem().str_repeat("\n", 65536 - strlen(dkimTestPem())));
        $task = dkimTask('dkim.inspect', ['domain' => 'example.org']);

        ($this->helper)()->run();

        expect($task->refresh()->result['keys'][0]['txt'])->toBe(dkimExpectedTxt(dkimTestPem()));
    });
});

describe('crafted task rows', function () {
    it('refuses them and writes nothing', function (string $type, mixed $payload, array $over, string $error) {
        ($this->key)('example.org.es20261011.key');
        $before = dkimTree($this->tmp);
        $task = dkimTask($type, $payload, $over);

        expect(($this->helper)()->run())->toBe(['done' => 0, 'failed' => 1, 'retried' => 0]);

        expect($task->refresh()->status)->toBe('failed')
            ->and($task->attempts)->toBe(1)
            ->and($task->result)->toBeNull()
            ->and($task->last_error)->toBe($error)
            ->and(dkimTree($this->tmp))->toBe($before);
    })->with([
        'dot-dot domain' => ['dkim.generate', ['domain' => '../outside'], [], 'refused: the domain is not a lowercase ASCII host name'],
        'domain climbing out' => ['dkim.generate', ['domain' => 'example.org/..'], [], 'refused: the domain is not a lowercase ASCII host name'],
        'slash in domain' => ['dkim.inspect', ['domain' => 'example.org/x'], [], 'refused: the domain is not a lowercase ASCII host name'],
        'unknown domain' => ['dkim.generate', ['domain' => 'unknown.example'], [], 'refused: unknown.example is not in virtual_domains'],
        'stored mixed case' => ['dkim.generate', ['domain' => 'Mixed.example'], [], 'refused: the domain is not a lowercase ASCII host name'],
        'case-folded spelling' => ['dkim.generate', ['domain' => 'mixed.example'], [], 'refused: mixed.example is not in virtual_domains'],
        'second map line' => ['dkim.activate', ['domain' => "example.org es20261011\nexample.net", 'selector' => 'es20261011'], [], 'refused: the domain is not a lowercase ASCII host name'],
        'space in domain' => ['dkim.activate', ['domain' => 'example.org other', 'selector' => 'es20261011'], [], 'refused: the domain is not a lowercase ASCII host name'],
        'trailing newline' => ['dkim.activate', ['domain' => "example.org\n", 'selector' => 'es20261011'], [], 'refused: the domain is not a lowercase ASCII host name'],
        'columns disagree' => ['dkim.activate', ['domain' => 'example.net', 'selector' => 'es20261011'], ['domain_name' => 'example.org'], 'refused: the payload domain differs from the task row'],
        'no domain' => ['dkim.generate', ['selector' => 'es20261011'], [], 'refused: the payload has no domain'],
        'payload not JSON object' => ['dkim.generate', '"example.org"', [], 'refused: the payload has no domain'],
        'no payload' => ['dkim.generate', 'null', [], 'refused: the payload has no domain'],
        'selector climbing out' => ['dkim.retire', ['domain' => 'example.org', 'selector' => '../../outside/x'], [], 'refused: the selector does not match es + YYYYMMDD + an optional letter'],
        'selector without es' => ['dkim.retire', ['domain' => 'example.org', 'selector' => '20261011'], [], 'refused: the selector does not match es + YYYYMMDD + an optional letter'],
        'upper-case selector' => ['dkim.activate', ['domain' => 'example.org', 'selector' => 'ES20261011'], [], 'refused: the selector does not match es + YYYYMMDD + an optional letter'],
        'two letters' => ['dkim.activate', ['domain' => 'example.org', 'selector' => 'es20261011ab'], [], 'refused: the selector does not match es + YYYYMMDD + an optional letter'],
        'selector with newline' => ['dkim.activate', ['domain' => 'example.org', 'selector' => "es20261011\nexample.net es20261011"], [], 'refused: the selector does not match es + YYYYMMDD + an optional letter'],
        'selector not a string' => ['dkim.activate', ['domain' => 'example.org', 'selector' => 20261011], [], 'refused: the selector does not match es + YYYYMMDD + an optional letter'],
        'no selector' => ['dkim.activate', ['domain' => 'example.org'], [], 'refused: the selector does not match es + YYYYMMDD + an optional letter'],
        'unknown type' => ['dkim.shred', ['domain' => 'example.org'], [], 'refused: unknown task type'],
    ]);

    it('refuses symlinks on any path it would touch, and leaves their targets alone', function (string $type, string $link, string $error) {
        $outsideKey = "{$this->tmp}/outside/example.org.es20261011.key";
        file_put_contents($outsideKey, dkimTestPem());
        file_put_contents("{$this->tmp}/outside/dkim_selectors.map", "example.org old\n");
        match ($link) {
            'key file' => symlink($outsideKey, "{$this->dkim}/example.org.es20261011.key"),
            'dkim dir' => [TempTree::remove($this->dkim), symlink("{$this->tmp}/outside", $this->dkim)],
            'map' => [($this->key)('example.org.es20261011.key'), unlink($this->map), symlink("{$this->tmp}/outside/dkim_selectors.map", $this->map)],
            'map dir' => [($this->key)('example.org.es20261011.key'), TempTree::remove($this->rspamd), symlink("{$this->tmp}/outside", $this->rspamd)],
        };
        $outside = dkimTree("{$this->tmp}/outside");
        $before = dkimTree($this->tmp);
        $task = dkimTask($type, ['domain' => 'example.org', 'selector' => 'es20261011']);

        ($this->helper)()->run();

        expect($task->refresh()->status)->toBe('failed')
            ->and($task->last_error)->toBe($error)
            ->and(dkimTree("{$this->tmp}/outside"))->toBe($outside)
            ->and(dkimTree($this->tmp))->toBe($before);
    })->with([
        'generate onto a symlinked key' => ['dkim.generate', 'key file', 'unsafe path: the key file example.org.es20261011.key is a symlink'],
        'activate a symlinked key' => ['dkim.activate', 'key file', 'unsafe path: the key file example.org.es20261011.key is a symlink'],
        'retire a symlinked key' => ['dkim.retire', 'key file', 'unsafe path: the key file example.org.es20261011.key is a symlink'],
        'inspect a symlinked key' => ['dkim.inspect', 'key file', 'unsafe path: the key file example.org.es20261011.key is a symlink'],
        'generate in a symlinked dkim dir' => ['dkim.generate', 'dkim dir', 'unsafe path: a component of the DKIM directory is a symlink'],
        'retire in a symlinked dkim dir' => ['dkim.retire', 'dkim dir', 'unsafe path: a component of the DKIM directory is a symlink'],
        'activate a symlinked map' => ['dkim.activate', 'map', 'unsafe path: the selector map is a symlink'],
        'inspect a symlinked map' => ['dkim.inspect', 'map', 'unsafe path: the selector map is a symlink'],
        'retire under a symlinked map' => ['dkim.retire', 'map', 'unsafe path: the selector map is a symlink'],
        'activate under a symlinked map dir' => ['dkim.activate', 'map dir', 'unsafe path: a component of the selector map\'s directory is a symlink'],
    ]);

    it('refuses a map that is not a regular file', function () {
        ($this->key)('example.org.es20261011.key');
        unlink($this->map);
        mkdir($this->map);
        $task = dkimTask('dkim.activate', ['domain' => 'example.org', 'selector' => 'es20261011']);

        ($this->helper)()->run();

        expect($task->refresh()->last_error)->toBe('unsafe path: the selector map is not a regular file')
            ->and($this->map)->toBeDirectory();
    });
});

describe('the queue', function () {
    it('claims only pending dkim tasks, oldest first, and resets rows a crash left running', function () {
        $maildir = dkimTask('maildir.archive', null, ['address' => 'gone@example.org', 'domain_name' => 'example.org']);
        $later = dkimTask('dkim.generate', ['domain' => 'example.org'], ['run_after' => now()->addHour()]);
        $first = dkimTask('dkim.generate', ['domain' => 'example.org'], ['status' => 'running']);
        $second = dkimTask('dkim.generate', ['domain' => 'example.org']);
        $done = dkimTask('dkim.generate', ['domain' => 'example.org'], ['status' => 'failed']);

        expect(($this->helper)()->run())->toBe(['done' => 2, 'failed' => 0, 'retried' => 0]);

        expect($first->refresh()->result['selector'])->toBe('es20261011')
            ->and($second->refresh()->result['selector'])->toBe('es20261011b')
            ->and($later->refresh()->status)->toBe('pending')
            ->and($maildir->refresh()->status)->toBe('pending')
            ->and($done->refresh()->status)->toBe('failed');
    });

    it('counts a run a crash left behind as an attempt, and fails it at the maximum', function () {
        $retried = dkimTask('dkim.generate', ['domain' => 'example.org'], ['status' => 'running', 'attempts' => 3]);
        $given = dkimTask('dkim.generate', ['domain' => 'example.org'], ['status' => 'running', 'attempts' => 4]);

        expect(($this->helper)()->run())->toBe(['done' => 1, 'failed' => 0, 'retried' => 0]);

        expect($retried->refresh()->status)->toBe('done')
            ->and($retried->attempts)->toBe(4)
            ->and($retried->last_error)->toBeNull()
            ->and($given->refresh()->status)->toBe('failed')
            ->and($given->attempts)->toBe(DkimHelper::MAX_ATTEMPTS)
            ->and($given->last_error)->toBe('the helper stopped while running this task')
            ->and(glob("{$this->dkim}/*.key"))->toHaveCount(1);
    });

    it('retries with backoff while the DKIM directory is missing, then fails', function (int $attempts, string $status) {
        rmdir($this->dkim);
        $task = dkimTask('dkim.generate', ['domain' => 'example.org'], ['attempts' => $attempts]);

        ($this->helper)()->run();

        $wait = DB::selectOne('SELECT TIMESTAMPDIFF(SECOND, NOW(), run_after) AS s FROM helper_tasks WHERE id = ?', [$task->id])->s;
        expect($task->refresh()->status)->toBe($status)
            ->and($task->attempts)->toBe($attempts + 1)
            ->and($task->last_error)->toBe('the DKIM directory is missing');
        if ($status === 'pending') {
            expect($wait)->toBeGreaterThanOrEqual(2 ** ($attempts + 1) * 60 - 5)->toBeLessThanOrEqual(2 ** ($attempts + 1) * 60 + 5);
        }
    })->with([[0, 'pending'], [2, 'pending'], [4, 'failed']]);

    it('leaves a task the app gave up on while it ran (a domain delete) failed', function () {
        $task = dkimTask('dkim.generate', ['domain' => 'example.org']);
        $this->onLog = fn () => DB::table('helper_tasks')->where('id', $task->id)
            ->update(['status' => 'failed', 'last_error' => 'domain deleted', 'note' => 'domain deleted']);

        ($this->helper)()->run();

        expect($task->refresh()->status)->toBe('failed')
            ->and($task->last_error)->toBe('domain deleted')
            ->and($task->note)->toBe('domain deleted')
            ->and($task->result)->toBeNull();
    });

    it('clears an earlier error once a retried task is done', function () {
        $task = dkimTask('dkim.generate', ['domain' => 'example.org'], ['attempts' => 1, 'last_error' => 'the DKIM directory is missing']);

        ($this->helper)()->run();

        expect($task->refresh()->status)->toBe('done')->and($task->last_error)->toBeNull();
    });
});

it('never lets private key material into a result, an error, a note or the log', function () {
    ($this->key)('example.org.2025121401.key', 0);
    ($this->key)('example.org.es20261011x.key', 1);
    file_put_contents("{$this->dkim}/example.org.es20261011y.key", "-----BEGIN PRIVATE KEY-----\nMIIEvQIBADANBgkqhkiG9w0BAQEFAASCBKcwggSjAgEAAoIBAQC\n-----END PRIVATE KEY-----\n");
    $tasks = [
        dkimTask('dkim.generate', ['domain' => 'example.org']),
        dkimTask('dkim.activate', ['domain' => 'example.org', 'selector' => 'es20261011x']),
        dkimTask('dkim.activate', ['domain' => 'example.org', 'selector' => 'es20261011y']),
        dkimTask('dkim.inspect', ['domain' => 'example.org']),
        dkimTask('dkim.retire', ['domain' => 'example.org', 'selector' => 'es20261011x']),
    ];

    ($this->helper)()->run();

    // A PKCS#8 RSA key's base64 body holds the public modulus up to about character 400; everything
    // after it (the private exponent, the primes, the CRT values) is secret.
    $secrets = [];
    $pems = [dkimTestPem(0), dkimTestPem(1), (string) file_get_contents("{$this->dkim}/example.org.es20261011.key")];
    foreach ($pems as $pem) {
        $body = preg_replace('/-----[A-Z ]+-----|\s+/', '', $pem);
        foreach (str_split(substr($body, 420), 32) as $chunk) {
            if (strlen($chunk) === 32) {
                $secrets[] = $chunk;
            }
        }
    }
    $texts = $this->log;
    foreach ($tasks as $task) {
        $raw = DB::table('helper_tasks')->where('id', $task->id)->first();
        $texts[] = (string) $raw->result;
        $texts[] = (string) $raw->last_error;
        $texts[] = (string) $raw->note;
    }
    $all = implode("\n", $texts);

    expect($all)->not->toContain('PRIVATE')
        ->and(count($secrets))->toBeGreaterThan(100)
        ->and(array_values(array_filter($secrets, fn (string $secret) => str_contains($all, $secret))))->toBe([])
        ->and($tasks[2]->refresh()->last_error)->toBe('activate refused: example.org.es20261011y.key is not an RSA private key');
});
