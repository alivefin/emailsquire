<?php

namespace App\Auth;

use Illuminate\Auth\Passwords\PasswordBrokerManager;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Both console token brokers share the same verification padding and configured hashing driver. */
final class ConsolePasswordBrokerManager extends PasswordBrokerManager
{
    /** @param string $name */
    protected function resolve($name): ConsolePasswordBroker
    {
        if ($this->getConfig($name) === null) {
            throw new InvalidArgumentException("Password resetter [{$name}] is not defined.");
        }
        $prefix = 'auth.passwords.'.$name.'.';
        if (config($prefix.'driver', 'database') !== 'database') {
            throw new InvalidArgumentException('Console password tokens require the database driver');
        }
        $connection = config($prefix.'connection');
        if ($connection !== null && ! is_string($connection)) {
            throw new InvalidArgumentException('Invalid password token database connection');
        }
        $key = config()->string('app.key');
        if (str_starts_with($key, 'base64:')) {
            $key = base64_decode(substr($key, 7), true);
            if ($key === false) {
                throw new InvalidArgumentException('Invalid application key encoding');
            }
        }
        $provider = Auth::createUserProvider(config()->string($prefix.'provider'));
        if ($provider === null) {
            throw new InvalidArgumentException('Console password user provider is not configured');
        }

        return new ConsolePasswordBroker(
            new ConsoleTokenRepository(
                DB::connection($connection),
                app('hash'),
                config()->string($prefix.'table'),
                $key,
                config()->integer($prefix.'expire', 60) * 60,
                config()->integer($prefix.'throttle', 0),
            ),
            $provider,
            $this->app->make('events'),
            timeboxDuration: config()->integer('auth.timebox_duration', 200000),
        );
    }
}
