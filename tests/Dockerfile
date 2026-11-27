FROM wordpress:php8.2-apache
RUN docker-php-ext-install pdo_mysql && a2enmod rewrite headers
RUN printf '%s\n' '<VirtualHost *:80>' 'DocumentRoot /app/public' '<Directory /app/public>' 'AllowOverride All' 'Require all granted' '</Directory>' '</VirtualHost>' > /etc/apache2/sites-available/000-default.conf
WORKDIR /app
ENTRYPOINT ["docker-php-entrypoint"]
CMD ["apache2-foreground"]
