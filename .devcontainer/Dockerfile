FROM php:8.3-apache
RUN docker-php-ext-install pdo_mysql \
 && a2enmod rewrite expires \
 && apt-get update && apt-get install -y --no-install-recommends git unzip default-mysql-client \
 && apt-get clean
ENV APACHE_DOCUMENT_ROOT=/workspaces/ess-iv-crm
RUN sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
 && sed -ri 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf \
 && printf '<Directory /workspaces/ess-iv-crm>\n  AllowOverride All\n  Require all granted\n</Directory>\n' > /etc/apache2/conf-available/ess4.conf \
 && a2enconf ess4 \
 && printf 'post_max_size=16M\nupload_max_filesize=16M\nmemory_limit=256M\n' > /usr/local/etc/php/conf.d/ess4.ini
