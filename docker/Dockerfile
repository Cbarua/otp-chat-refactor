# syntax=docker/dockerfile:1
# Same stack as the AWS OTP server that scripts/deploy.sh targets: Amazon Linux 2023,
# httpd 2.4 and PHP 8.4 behind php-fpm. The package list is `rpm -qa` from the server
# on 2026-10-26, minus mod_ssl (no TLS locally).
FROM public.ecr.aws/amazonlinux/amazonlinux:2023

RUN dnf -y install httpd php8.4 php8.4-cli php8.4-common php8.4-fpm php8.4-mbstring \
        php8.4-opcache php8.4-pdo php8.4-process php8.4-sodium php8.4-xml \
    && dnf clean all \
    && echo 'session.upload_progress.enabled = 0' > /etc/php.d/99-hardening.ini

# One vhost per test port: the app on 8080 (its public/.htaccess applies, as on the
# server), the mock OTP provider on 8081. APP_ROOT is the repo, mounted at the same
# path as on the host so the TEST_LOG_DIR cookie paths the tests send resolve here.
COPY <<'EOF' /etc/httpd/conf.d/otp-chat.conf
Listen 8080
Listen 8081
ErrorLog /dev/stderr

<VirtualHost *:8080>
    DocumentRoot "${APP_ROOT}/public"
    <Directory "${APP_ROOT}/public">
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>

<VirtualHost *:8081>
    DocumentRoot "${APP_ROOT}/public/backend-test-api"
    FallbackResource /index.php
    <Directory "${APP_ROOT}/public/backend-test-api">
        Require all granted
    </Directory>
</VirtualHost>
EOF

# systemd normally creates the runtime dirs; php-fpm daemonises itself (package default).
CMD mkdir -p /run/php-fpm /run/httpd && php-fpm && exec httpd -DFOREGROUND
