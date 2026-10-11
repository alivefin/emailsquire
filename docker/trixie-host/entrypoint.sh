#!/bin/bash
# Starts MariaDB, rspamd, Postfix and Dovecot (no systemd in the container), then runs the given command.
# The guide schema itself is created by the tests (Tests\Support\GuideFixture), not here.
set -e
mkdir -p /run/mysqld && chown mysql:mysql /run/mysqld
mariadbd-safe >/dev/null 2>&1 &
for i in $(seq 1 50); do mariadb -e 'select 1' >/dev/null 2>&1 && break; sleep 0.2; done
mariadb <<'SQL'
CREATE DATABASE IF NOT EXISTS mailserver;
CREATE DATABASE IF NOT EXISTS emailsquire_test;
CREATE USER IF NOT EXISTS 'mailserver'@'127.0.0.1' IDENTIFIED BY 'mailserver-test';
GRANT SELECT ON mailserver.* TO 'mailserver'@'127.0.0.1';
CREATE USER IF NOT EXISTS 'test'@'%' IDENTIFIED BY 'test';
GRANT ALL PRIVILEGES ON *.* TO 'test'@'%' WITH GRANT OPTION;
SQL
# rspamd before Postfix: Postfix hands every message to its milter (11332) and defers mail while it's down.
install -d -o _rspamd -g _rspamd /run/rspamd
rspamd -u _rspamd -g _rspamd
for i in $(seq 1 100); do (echo > /dev/tcp/127.0.0.1/11332) 2>/dev/null && break; sleep 0.2; done
postfix start   # before Dovecot: it creates /var/spool/postfix/private, where Dovecot puts its sockets
dovecot
exec "$@"
