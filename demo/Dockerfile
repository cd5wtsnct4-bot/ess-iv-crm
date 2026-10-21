# ESS IV CRM: self-contained LIVE DEMO image (PHP + Apache + MariaDB in one container).
# Example data loads automatically on start and resets whenever the container restarts.
# Not for production: use install.php on a normal PHP/MySQL host for that (see DEPLOY.md).
#
#   docker build -f demo/Dockerfile -t ess-iv-crm-demo .
#   docker run --rm -p 8080:8080 ess-iv-crm-demo        → http://localhost:8080
FROM php:8.3-apache

RUN apt-get update \
 && apt-get install -y --no-install-recommends mariadb-server nodejs \
 && apt-get clean \
 && docker-php-ext-install pdo_mysql \
 && a2enmod rewrite expires headers \
 && sed -ri 's!(<Directory /var/www/>[^<]*AllowOverride) None!\1 All!' /etc/apache2/apache2.conf \
 && sed -ri '/<Directory \/var\/www\/>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf \
 && printf 'post_max_size=16M\nupload_max_filesize=16M\nmemory_limit=256M\nmax_execution_time=300\ndate.timezone=Africa/Johannesburg\n' > /usr/local/etc/php/conf.d/ess4.ini \
 && printf '[mysqld]\ninnodb_buffer_pool_size=64M\nperformance_schema=OFF\nskip-name-resolve\nbind-address=127.0.0.1\n' > /etc/mysql/mariadb.conf.d/99-demo.cnf

WORKDIR /var/www/html
COPY . /var/www/html
RUN rm -rf .git .devcontainer docs tools config.php install.lock \
 && chmod +x demo/start.sh \
 && chown -R www-data:www-data /var/www/html

ENV PORT=8080 TZ=Africa/Johannesburg
EXPOSE 8080
CMD ["/var/www/html/demo/start.sh"]
