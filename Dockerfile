FROM php:8.2-fpm-alpine

RUN adduser -h /relana -s /bin/false -D -H relana && \
    mkdir /usr/src/relana && \
    chown relana:relana /usr/src/relana

COPY --chown=relana:relana src /usr/src/relana/src
COPY --chown=relana:relana img /usr/src/relana/img
COPY --chown=relana:relana composer.json composer.lock check.php /usr/src/relana/

RUN wget -O /usr/bin/composer https://getcomposer.org/composer-2.phar && \
    apk --no-cache add \
        sudo \
        jq \
    && \
    cd /usr/src/relana && \
    sudo -u relana php /usr/bin/composer install --no-dev --no-interaction --no-progress -q && \
    sudo -u relana php /usr/bin/composer dumpautoload --no-dev --optimize --no-interaction 2>&1 | grep -v "/20[0-9]\{12\}_.*autoload" && \
    sudo -u relana php /usr/bin/composer clear-cache -q && \
    rm -f /usr/bin/composer && \
    apk del --no-cache sudo jq && \
    mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
