# ─── FrankenPHP (PHP 8.4 + Caddy) — LOCAL DEVELOPMENT image ───────────────────
# FrankenPHP bundles Caddy + PHP in a single container (no nginx / php-fpm).
# This image is for `make up` only. Production is NOT built from it: cookbook is
# deployed to OVH mutualisé over SSH by .github/workflows/deploy.yml — see DEPLOY.md.
#
# PHP 8.4 on purpose: it is what CI runs (.github/workflows/ci.yml) and what the
# OVH host serves. composer.json declares >=8.2, but nothing is validated on 8.2.
FROM dunglas/frankenphp:php8.4

# install-php-extensions ships with the FrankenPHP image and compiles the right
# extensions for the current architecture (amd64 / arm64).
# pdo_pgsql — this project runs on PostgreSQL, not MySQL.
RUN install-php-extensions \
    pdo_pgsql \
    intl \
    zip \
    opcache \
    mbstring \
    gd

# Development OPcache: revalidate on every request so code edits are picked up
# immediately (the app source is bind-mounted).
RUN { \
      echo "opcache.enable=1"; \
      echo "opcache.enable_cli=0"; \
      echo "opcache.validate_timestamps=1"; \
      echo "opcache.revalidate_freq=0"; \
    } > /usr/local/etc/php/conf.d/opcache-dev.ini

# Dev PHP settings. The generous memory_limit is what `app:import-csv` needs when
# it walks the recipe CSVs (the README used to prescribe -d memory_limit=1024M).
RUN { \
      echo "memory_limit=1024M"; \
      echo "upload_max_filesize=12M"; \
      echo "post_max_size=13M"; \
      echo "date.timezone=Europe/Paris"; \
    } > /usr/local/etc/php/conf.d/dev.ini

# Composer (latest stable) + Symfony CLI, handy inside the container.
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer
RUN curl -sS https://get.symfony.com/cli/installer | bash \
    && mv /root/.symfony*/bin/symfony /usr/local/bin/symfony

WORKDIR /app

# Bootstraps the app on boot (composer install, wait for DB, JWT keys, migrations)
# then hands off to FrankenPHP.
COPY docker/frankenphp/dev-entrypoint.sh /usr/local/bin/dev-entrypoint
RUN chmod +x /usr/local/bin/dev-entrypoint

ENTRYPOINT ["dev-entrypoint"]
