FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libonig-dev libonig5 \
    && docker-php-ext-install pdo_mysql mbstring \
    && apt-get purge -y --auto-remove libonig-dev \
    && rm -rf /var/lib/apt/lists/* \
    && a2enmod rewrite headers

COPY docker/apache-vhost.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-certa.ini
COPY docker/entrypoint.sh /usr/local/bin/certa-entrypoint
COPY --chown=www-data:www-data . /var/www/html
RUN chmod 755 /usr/local/bin/certa-entrypoint

ENTRYPOINT ["/usr/local/bin/certa-entrypoint"]
CMD ["apache2-foreground"]

EXPOSE 80
