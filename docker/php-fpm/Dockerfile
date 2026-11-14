ARG PHP_FPM_BASE_IMAGE=php:8.4.27-fpm-bookworm@sha256:b75642e8c89c321039f351466134df951576fb0f7017117b3931e9a19b908d6c
FROM node:24.22.3-bookworm-slim@sha256:dc26ae92c9fb15cf6b1d63e20f0944b2efe0f115b20e00be48fb23fceb061c54 AS node-runtime

FROM ${PHP_FPM_BASE_IMAGE}

COPY --from=node-runtime /usr/local/bin/node /usr/local/bin/node
COPY --from=node-runtime /usr/local/lib/node_modules /usr/local/lib/node_modules

WORKDIR "/var/www/html"

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        procps \
        zip \
        unzip \
    && curl -fsSL https://github.com/mlocati/docker-php-extension-installer/releases/download/2.13.1/install-php-extensions -o /tmp/install-php-extensions \
    && echo '3c3f3de26458405199f1f3a4c7130fdd2056daf596c05512badd769a77019b59  /tmp/install-php-extensions' | sha256sum -c - \
    && chmod +x /tmp/install-php-extensions \
    && /tmp/install-php-extensions \
        curl gd intl ldap mbstring mysqli pdo pdo_mysql sockets xml zip xdebug exif sqlite3 gettext bcmath \
    && rm -f /tmp/install-php-extensions \
    && docker-php-ext-enable xdebug \
    && curl -fsSL https://getcomposer.org/download/2.11.2/composer.phar -o /usr/local/bin/composer \
    && echo 'dd158c0afd41d15fac89e1b4e47b5d42911fe316c3687cd5edac98319b25d028  /usr/local/bin/composer' | sha256sum -c - \
    && chmod 0755 /usr/local/bin/composer \
    && ln -s ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -s ../lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx \
    && node -e "const [major, minor, patch] = process.versions.node.split('.').map(Number); if (major !== 24 || minor < 22 || (minor === 22 && patch < 3)) process.exit(1);" \
    && echo "alias ll=\"ls -al\"" >> /root/.bashrc \
    && echo "export XDEBUG_TRIGGER=1" >> /root/.bashrc \
    && echo "export PHP_IDE_CONFIG=\"serverName=host.docker.internal\"" >> /root/.bashrc \
    && apt-get -y autoremove \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*

CMD ["bash", "docker/php-fpm/start-container"]
