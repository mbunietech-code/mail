# Local production-like PHP-FPM for validating infra/ configs (not a production image).
FROM php:8.4-fpm
RUN apt-get update && apt-get install -y --no-install-recommends libicu-dev libzip-dev libpng-dev \
 && docker-php-ext-install pdo_mysql intl zip bcmath gd \
 && rm -rf /var/lib/apt/lists/* \
 && groupadd -g 1000 mbuniemail && useradd -u 1000 -g 1000 -M -d /srv/mbuniemail -s /usr/sbin/nologin mbuniemail \
 && mkdir -p /run/php /var/log/php /etc/mbuniemail/keys && chown mbuniemail:mbuniemail /var/log/php /etc/mbuniemail/keys \
 && rm -f /usr/local/etc/php-fpm.d/www.conf /usr/local/etc/php-fpm.d/zz-docker.conf \
 && cp /usr/local/etc/php/php.ini-production /usr/local/etc/php/php.ini
RUN rm -f /usr/local/etc/php-fpm.d/docker.conf
COPY mbuniemail-fpm.conf /usr/local/etc/php-fpm.d/mbuniemail.conf
CMD ["php-fpm", "-F"]
