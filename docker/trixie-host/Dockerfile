FROM debian:trixie
ENV DEBIAN_FRONTEND=noninteractive
RUN echo "postfix postfix/main_mailer_type select Internet Site" | debconf-set-selections \
 && echo "postfix postfix/mailname string mail.example.test" | debconf-set-selections \
 && apt-get update && apt-get install -y --no-install-recommends \
    mariadb-server postfix postfix-mysql dovecot-core dovecot-imapd dovecot-lmtpd dovecot-mysql \
    swaks ssl-cert ca-certificates curl jq procps \
    php8.4-cli php8.4-mysql php8.4-intl php8.4-mbstring php8.4-xml php8.4-curl \
 && rm -rf /var/lib/apt/lists/*
COPY setup.sh entrypoint.sh /usr/local/sbin/
RUN chmod +x /usr/local/sbin/*.sh && /usr/local/sbin/setup.sh
ENTRYPOINT ["/usr/local/sbin/entrypoint.sh"]
