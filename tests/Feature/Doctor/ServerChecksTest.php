<?php

use App\Doctor\Checks\DkimDir;
use App\Doctor\Checks\DoveadmApi;
use App\Doctor\Checks\DovecotAuthSocket;
use App\Doctor\Checks\FpmIni;
use App\Doctor\Checks\MailHost;
use App\Doctor\Checks\MailQueueWorker;
use App\Doctor\Checks\MailRoot;
use App\Doctor\Checks\MailSmtp;
use App\Doctor\Checks\MailWebmailUrl;
use App\Doctor\Checks\PostfixAliasMaps;
use App\Doctor\Checks\PostfixSenderLoginMaps;
use App\Doctor\Checks\RequireTwoFactorSetting;
use App\Doctor\Checks\RuntimeCaches;
use App\Doctor\Checks\RuntimeDebug;
use App\Doctor\Checks\RuntimeTestEnvironment;
use App\Doctor\Doctor;
use App\Doctor\Status;
use App\MailServer\Dovecot\DovecotAuthClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

const EMAIL2EMAIL_QUERY = "SELECT email FROM virtual_users WHERE email='%s'";
const UNION_QUERY = "SELECT email FROM virtual_users WHERE email='%s' UNION SELECT destination FROM virtual_aliases WHERE source='%s';";

/** Writes an installer-style Postfix tree and points the config at it. */
function postfixTree(string $senderMap = 'mariadb-email2email.cf', string $password = 'secret', int $mode = 0640): string
{
    $dir = trackTemp(sys_get_temp_dir().'/doctor-'.bin2hex(random_bytes(4)));
    mkdir($dir);

    $cf = fn (string $query) => "user = mailserver\npassword = {$password}\nhosts = 127.0.0.1\ndbname = mailserver\nquery = {$query}\n";
    file_put_contents("{$dir}/mariadb-virtual-alias-maps.cf", $cf('SELECT destination FROM virtual_aliases WHERE source=\'%s\''));
    file_put_contents("{$dir}/mariadb-email2email.cf", $cf(EMAIL2EMAIL_QUERY));
    file_put_contents("{$dir}/mariadb-sender-login-maps.cf", $cf(UNION_QUERY));
    foreach (glob("{$dir}/*.cf") as $file) {
        chmod($file, $mode);
    }

    file_put_contents("{$dir}/main.cf", "virtual_alias_maps = mysql:{$dir}/mariadb-virtual-alias-maps.cf,mysql:{$dir}/mariadb-email2email.cf\n"
        ."smtpd_sender_login_maps = mysql:{$dir}/{$senderMap}\n");
    config()->set('emailsquire.paths.postfix_main_cf', "{$dir}/main.cf");

    return $dir;
}

beforeEach(fn () => isolateDoctorFromHost(fakeDoveadm: false));

/** Temp paths this file's tests create, removed again by the afterEach below. */
function trackTemp(string $path): string
{
    $GLOBALS['serverChecksTemp'][] = $path;

    return $path;
}

function removeTemp(string $path): void
{
    if (is_link($path) || (file_exists($path) && ! is_dir($path))) {
        @unlink($path);

        return;
    }
    if (! is_dir($path)) {
        return;
    }
    chmod($path, 0700);
    foreach (array_diff((array) scandir($path), ['.', '..']) as $entry) {
        removeTemp("{$path}/{$entry}");
    }
    rmdir($path);
}

afterEach(function () {
    foreach ($GLOBALS['serverChecksTemp'] ?? [] as $path) {
        removeTemp($path);
    }
    $GLOBALS['serverChecksTemp'] = [];
});

function withEnvironment(string $env, Closure $callback): void
{
    $previous = app()['env'];
    app()['env'] = $env;
    try {
        expect(app()->environment($env))->toBeTrue();
        $callback();
    } finally {
        app()['env'] = $previous;
    }
}

it('accepts the installer final state for alias and sender maps', function () {
    postfixTree();

    expect(app(PostfixAliasMaps::class)->run())->status->toBe(Status::Ok)->detail->toContain('catch-all supported');
    expect(app(PostfixSenderLoginMaps::class)->run())->status->toBe(Status::Ok)->detail->toContain('own address only');
});

it('recognises the email2email query by content when the file is named differently', function () {
    $dir = postfixTree();
    rename("{$dir}/mariadb-email2email.cf", "{$dir}/renamed.cf");
    file_put_contents("{$dir}/main.cf", "virtual_alias_maps = mysql:{$dir}/renamed.cf\n");

    expect(app(PostfixAliasMaps::class)->run()->status)->toBe(Status::Ok);
});

it('warns when no alias map supports catch-all', function () {
    $dir = postfixTree();
    file_put_contents("{$dir}/main.cf", "virtual_alias_maps = mysql:{$dir}/mariadb-virtual-alias-maps.cf\n");

    expect(app(PostfixAliasMaps::class)->run())->status->toBe(Status::Warn)->detail->toContain('catch-all aliases will be refused');
});

it('says aliases grant send-as for the union query', function () {
    postfixTree('mariadb-sender-login-maps.cf');

    expect(app(PostfixSenderLoginMaps::class)->run())->status->toBe(Status::Ok)->detail->toContain('aliases grant send-as');
});

it('warns on an empty sender login map', function () {
    $dir = postfixTree();
    file_put_contents("{$dir}/main.cf", "smtpd_sender_login_maps =\n");

    expect(app(PostfixSenderLoginMaps::class)->run()->status)->toBe(Status::Warn);
});

it('fails when an active map still has the installer placeholder password', function () {
    postfixTree('mariadb-email2email.cf', 'MAILSERVER-PASSWORD-HERE');

    expect(app(PostfixSenderLoginMaps::class)->run())->status->toBe(Status::Fail)->detail->toContain('MAILSERVER-PASSWORD-HERE');
});

it('skips with a run-as-root hint when an active map is unreadable', function () {
    postfixTree('mariadb-email2email.cf', 'secret', 0000);

    expect(app(PostfixSenderLoginMaps::class)->run())->status->toBe(Status::Skip)->detail->toContain('run as root');
})->skip(fn () => posix_getuid() === 0, 'root can read mode 0000 files');

it('server checks skip without --require-server', function () {
    config()->set('emailsquire.paths.postfix_main_cf', '/nonexistent/main.cf');

    expect(app(PostfixAliasMaps::class)->run()->status)->toBe(Status::Skip)
        ->and(app(PostfixSenderLoginMaps::class)->run()->status)->toBe(Status::Skip);
});

function fakeDoveadmReply(array|int $reply): void
{
    config()->set('emailsquire.doveadm.api_key', 'k');
    Http::fake(fn (Request $request) => is_int($reply)
        ? Http::response('', $reply)
        : Http::response([[$reply[0], $reply[1], $request->data()[0][2]]]));
}

it('treats doveadm exit code 67 as a working api', function () {
    fakeDoveadmReply(['error', ['type' => 'exitCode', 'exitCode' => 67]]);

    expect(app(DoveadmApi::class)->run()->status)->toBe(Status::Ok);
});

it('fails the doveadm check when the key is rejected', function () {
    fakeDoveadmReply(401);

    expect(app(DoveadmApi::class)->run()->status)->toBe(Status::Fail);
});

it('skips the doveadm check when the api is unreachable', function () {
    config()->set('emailsquire.doveadm.api_key', 'k');
    Http::fake(fn () => throw new ConnectionException('refused'));

    expect(app(DoveadmApi::class)->run()->status)->toBe(Status::Skip);
});

it('fails the doveadm check on other command errors', function () {
    fakeDoveadmReply(['error', ['type' => 'exitCode', 'exitCode' => 75]]);

    expect(app(DoveadmApi::class)->run()->status)->toBe(Status::Fail);
});

it('fails the doveadm check on an empty api key without calling out', function () {
    Http::fake();
    config()->set('emailsquire.doveadm.api_key', null);

    expect(app(DoveadmApi::class)->run())->status->toBe(Status::Fail)->detail->toContain('DOVEADM_API_KEY');
    Http::assertNothingSent();
});

it('skips a missing auth socket, and fails it with --require-server', function () {
    config()->set('emailsquire.dovecot_auth.socket', '/nonexistent/auth.sock');
    app()->forgetInstance(DovecotAuthClient::class);
    Http::fake(fn () => throw new ConnectionException('refused')); // Doctor::run() also calls doveadm

    expect(app(DovecotAuthSocket::class)->run()->status)->toBe(Status::Skip);

    $results = collect(app(Doctor::class)->run(true))->keyBy('id');
    expect($results['dovecot.auth_socket']->status)->toBe(Status::Fail);
});

/**
 * Creates a temp unix socket with the given mode (the file outlives the closed listener) and binds
 * the check as if run as root.
 */
function rootAuthSocket(int $mode): string
{
    $path = trackTemp(sys_get_temp_dir().'/es-auth-'.bin2hex(random_bytes(4)));
    $server = stream_socket_server("unix://{$path}");
    expect($server)->not->toBeFalse();
    fclose($server);
    chmod($path, $mode);
    expect(filetype($path))->toBe('socket');
    config()->set('emailsquire.dovecot_auth.socket', $path);
    app()->forgetInstance(DovecotAuthClient::class);
    app()->bind(DovecotAuthSocket::class, fn ($app) => new class($app->make(DovecotAuthClient::class)) extends DovecotAuthSocket
    {
        protected function isRoot(): bool
        {
            return true;
        }
    });

    return $path;
}

it('fails the auth socket under root when its owner is not the app user', function () {
    $path = rootAuthSocket(0600);
    config()->set('emailsquire.app_user', 'no-such-user-emailsquire');
    $me = posix_getpwuid(posix_geteuid())['name'];

    expect(app(DovecotAuthSocket::class)->run())->status->toBe(Status::Fail)
        ->detail->toBe("{$path} must be owned by no-such-user-emailsquire with mode 0600, but is owned by {$me} with mode 0600");

    unlink($path);
});

it('fails the auth socket under root when its mode is not 0600', function () {
    $path = rootAuthSocket(0644);
    $me = posix_getpwuid(posix_geteuid())['name'];
    config()->set('emailsquire.app_user', $me);

    expect(app(DovecotAuthSocket::class)->run())->status->toBe(Status::Fail)
        ->detail->toBe("{$path} must be owned by {$me} with mode 0600, but is owned by {$me} with mode 0644");

    unlink($path);
});

it('fails the mail root when its owner is not the configured vmail user', function () {
    $root = trackTemp(sys_get_temp_dir().'/doctor-root-'.bin2hex(random_bytes(4)));
    mkdir($root);
    config()->set('emailsquire.paths.mail_root', $root);
    config()->set('emailsquire.paths.trash_dir', "{$root}/.trash");
    config()->set('emailsquire.vmail_user', 'no-such-user-emailsquire');

    expect(app(MailRoot::class)->run())->status->toBe(Status::Fail)->detail->toContain('no-such-user-emailsquire');
});

it('passes the mail root when the owner matches and the trash is on the same device', function () {
    $root = trackTemp(sys_get_temp_dir().'/doctor-root-'.bin2hex(random_bytes(4)));
    mkdir("{$root}/.trash", 0777, true);
    config()->set('emailsquire.paths.mail_root', $root);
    config()->set('emailsquire.paths.trash_dir', "{$root}/.trash");
    config()->set('emailsquire.vmail_user', posix_getpwuid(posix_geteuid())['name']);

    expect(app(MailRoot::class)->run()->status)->toBe(Status::Ok);
});

it('skips the mail root when missing', function () {
    config()->set('emailsquire.paths.mail_root', '/nonexistent/vmail');

    expect(app(MailRoot::class)->run()->status)->toBe(Status::Skip);
});

it('only warns about the dkim dir owner and skips when missing', function () {
    $dir = trackTemp(sys_get_temp_dir().'/doctor-dkim-'.bin2hex(random_bytes(4)));
    mkdir($dir);
    config()->set('emailsquire.paths.dkim_dir', $dir);
    config()->set('emailsquire.rspamd_user', 'no-such-user-emailsquire');
    expect(app(DkimDir::class)->run()->status)->toBe(Status::Warn);

    config()->set('emailsquire.paths.dkim_dir', '/nonexistent/dkim');
    expect(app(DkimDir::class)->run()->status)->toBe(Status::Skip);
});

it('warns outside production, naming APP_DEBUG when it is on', function () {
    config()->set('app.debug', false);
    expect(app(RuntimeDebug::class)->run())->status->toBe(Status::Warn)
        ->detail->toBe('APP_ENV is testing, not production');

    config()->set('app.debug', true);
    expect(app(RuntimeDebug::class)->run())->status->toBe(Status::Warn)
        ->detail->toBe('APP_ENV is testing, not production; APP_DEBUG is on');

    withEnvironment('local', fn () => expect(app(RuntimeDebug::class)->run()->detail)
        ->toBe('APP_ENV is local, not production; APP_DEBUG is on'));
});

it('fails app.debug in production and passes it when off', function () {
    withEnvironment('production', function () {
        config()->set('app.debug', true);
        expect(app(RuntimeDebug::class)->run())->status->toBe(Status::Fail)->detail->toBe('APP_DEBUG is true in production');
        config()->set('app.debug', false);
        expect(app(RuntimeDebug::class)->run()->status)->toBe(Status::Ok);
    });
});

it('warns about missing caches only in production', function () {
    expect(app(RuntimeCaches::class)->run()->status)->toBe(Status::Skip);

    // Neither cache, whatever a developer's own bootstrap/cache holds (both flags are memoised bindings).
    app()->instance('config_loaded_from_cache', false);
    app()->instance('routes.cached', false);

    withEnvironment('production', function () {
        expect(app(RuntimeCaches::class)->run())->status->toBe(Status::Warn)
            ->detail->toBe('Not cached: config, routes (run php artisan optimize)');
    });
});

function fpmIni(string $contents): void
{
    $path = trackTemp((string) tempnam(sys_get_temp_dir(), 'fpm'));
    file_put_contents($path, $contents);
    config()->set('emailsquire.paths.fpm_php_ini', $path);
}

it('fails fpm ini in production when disable_functions lacks process functions', function () {
    fpmIni("disable_functions = exec,system\n");

    withEnvironment('production', function () {
        $result = app(FpmIni::class)->run();
        expect($result->status)->toBe(Status::Fail)
            ->and($result->detail)->toContain('shell_exec, passthru, proc_open, popen')
            ->and($result->detail)->not->toContain('exec, system');
    });
});

it('passes fpm ini when all process functions are disabled', function () {
    fpmIni("disable_functions = exec, shell_exec, system, passthru, proc_open, popen, curl_exec\nzend.exception_ignore_args = On\n");

    expect(app(FpmIni::class)->run()->status)->toBe(Status::Ok);
});

it('warns when fpm ini keeps exception arguments', function (string $extra) {
    fpmIni("disable_functions = exec, shell_exec, system, passthru, proc_open, popen\n{$extra}");

    $result = app(FpmIni::class)->run();
    expect($result->status)->toBe(Status::Warn)
        ->and($result->detail)->toContain('zend.exception_ignore_args');
})->with(['unset' => '', 'off' => "zend.exception_ignore_args = Off\n"]);

it('accepts 1 for zend.exception_ignore_args and still fails a missing function first', function () {
    fpmIni("disable_functions = exec, shell_exec, system, passthru, proc_open, popen\nzend.exception_ignore_args = 1\n");
    expect(app(FpmIni::class)->run()->status)->toBe(Status::Ok);

    fpmIni("disable_functions = exec\n");
    expect(app(FpmIni::class)->run()->status)->toBe(Status::Fail);
});

it('skips a missing fpm ini outside production and fails it in production', function () {
    config()->set('emailsquire.paths.fpm_php_ini', '/nonexistent/php.ini');

    expect(app(FpmIni::class)->run()->status)->toBe(Status::Skip);
    withEnvironment('production', fn () => expect(app(FpmIni::class)->run()->status)->toBe(Status::Fail));
});

it('registers the new checks after the database ones', function () {
    expect(array_slice(Doctor::CHECKS, -15))->toBe([
        PostfixAliasMaps::class, PostfixSenderLoginMaps::class, DoveadmApi::class, DovecotAuthSocket::class,
        MailRoot::class, DkimDir::class, RuntimeDebug::class, RuntimeCaches::class, FpmIni::class,
        MailQueueWorker::class, MailSmtp::class, MailHost::class, MailWebmailUrl::class, RequireTwoFactorSetting::class,
        RuntimeTestEnvironment::class,
    ]);
});
