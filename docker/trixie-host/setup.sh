#!/bin/bash
# Builds a mail host configured like the ISPmail Trixie installer (https://workaround.org/ispmail.sh,
# v0.5 of 2026-08-03), for tests. Deviations from the installer, all test-only:
#  - snakeoil certs instead of Let's Encrypt; smtpd_tls_security_level=may instead of encrypt
#  - fixed DB password 'mailserver-test'; no Roundcube, Apache, rspamd or quota warning script
#  - postfix logs to /var/log/postfix.log (no syslog in the container)
set -euo pipefail
# --- Postfix: mirrors ispmail.sh v0.5 postfix_config, lmtp_config, relaying_setup, catchall_config
for m in virtual-mailbox-domains:"SELECT 1 FROM virtual_domains WHERE name='%s'" \
         virtual-alias-maps:"SELECT destination FROM virtual_aliases WHERE source='%s'" \
         virtual-mailbox-maps:"SELECT 1 FROM virtual_users WHERE email='%s'" \
         sender-login-maps:"SELECT email FROM virtual_users WHERE email='%s' UNION SELECT destination FROM virtual_aliases WHERE source='%s';" \
         email2email:"SELECT email FROM virtual_users WHERE email='%s'"; do
  name=${m%%:*}; q=${m#*:}
  printf 'user = mailserver\npassword = mailserver-test\nhosts = 127.0.0.1\ndbname = mailserver\nquery = %s\n' "$q" > /etc/postfix/mariadb-$name.cf
done
chown root:postfix /etc/postfix/mariadb-*.cf; chmod u=rw,g=r,o= /etc/postfix/mariadb-*.cf
postconf myhostname=mail.example.test mydestination=localhost inet_interfaces=all
postconf virtual_mailbox_domains=mysql:/etc/postfix/mariadb-virtual-mailbox-domains.cf
postconf virtual_mailbox_maps=mysql:/etc/postfix/mariadb-virtual-mailbox-maps.cf
postconf virtual_alias_maps=mysql:/etc/postfix/mariadb-virtual-alias-maps.cf,mysql:/etc/postfix/mariadb-email2email.cf
postconf smtpd_sender_login_maps=mysql:/etc/postfix/mariadb-email2email.cf
postconf virtual_transport=lmtp:unix:private/dovecot-lmtp
postconf smtpd_tls_cert_file=/etc/ssl/certs/ssl-cert-snakeoil.pem smtpd_tls_key_file=/etc/ssl/private/ssl-cert-snakeoil.key
postconf smtpd_tls_security_level=may maillog_file=/var/log/postfix.log
# --- Dovecot: mirrors dovecot_config, lmtp_config, quota_config (no warning script)
groupadd -f --system vmail; id vmail >/dev/null 2>&1 || useradd --system --gid vmail vmail
mkdir -p /var/vmail; chown -R vmail:vmail /var/vmail; chmod u=rwx,g=rx,o= /var/vmail
sed -i '/!include/s/^/#/' /etc/dovecot/conf.d/10-auth.conf
cat > /etc/dovecot/conf.d/99-ispmail-mail.conf <<'C'
mail_driver = maildir
mail_home = /var/vmail/%{user | domain}/%{user | username}
mail_path = ~/Maildir
mail_uid = vmail
mail_gid = vmail
mail_inbox_path = ~/Maildir/
C
cat > /etc/dovecot/conf.d/99-ispmail-master.conf <<'C'
service auth {
  unix_listener /var/spool/postfix/private/dovecot-auth {
    mode = 0660
    user = postfix
    group = postfix
  }
}
C
cat > /etc/dovecot/conf.d/99-ispmail-ssl.conf <<'C'
ssl = required
ssl_server_cert_file = /etc/ssl/certs/ssl-cert-snakeoil.pem
ssl_server_key_file = /etc/ssl/private/ssl-cert-snakeoil.key
C
cat > /etc/dovecot/conf.d/99-ispmail-lmtp-username-format.conf <<'C'
protocol lmtp {
  auth_username_format =
}
C
cat > /etc/dovecot/conf.d/99-ispmail-sql.conf <<'C'
sql_driver = mysql

mysql /var/run/mysqld/mysqld.sock {
  user = mailserver
  password = 'mailserver-test'
  dbname = mailserver
  host = 127.0.0.1
}

userdb sql {
  query = SELECT email as user, \
    IF(quota > 0, CONCAT(quota, 'B'), NULL) AS quota_storage_size \
    FROM virtual_users WHERE email='%{user}'
  iterate_query = SELECT email as user FROM virtual_users
}

passdb sql {
  query = SELECT password FROM virtual_users where email='%{user}'
}
C
chmod go= /etc/dovecot/conf.d/99-ispmail-sql.conf
cat > /etc/dovecot/conf.d/99-ispmail-lmtp-listener.conf <<'C'
service lmtp {
  unix_listener lmtp {
  }
  unix_listener /var/spool/postfix/private/dovecot-lmtp {
    mode = 0600
    user = postfix
    group = postfix
  }
}
C
cat > /etc/dovecot/conf.d/99-ispmail-quota.conf <<'C'
mail_plugins {
  quota = yes
}
quota_storage_size = 5G
quota_storage_grace = 50M
quota "User quota" {
}
C
# --- Added by mailadmin (not part of the guide). Same content the app's install docs prescribe.
useradd --system --no-create-home --shell /usr/sbin/nologin mailadmin
cat > /etc/dovecot/conf.d/99-mailadmin.conf <<'C'
doveadm_api_key = test-api-key
service doveadm {
  inet_listener http {
    listen = 127.0.0.1
    port = 8080
  }
}
service auth {
  unix_listener mailadmin-auth {
    mode = 0600
    user = mailadmin
  }
}
C
