# Base image لاراول
FROM base/local-php-laravel-base-image:8.2

# Base image لاراول
FROM base/local-php-laravel-base-image:8.2

WORKDIR /var/www/html

# نصب ابزارها
RUN apt-get update -o Acquire::AllowInsecureRepositories=true \
    && apt-get install -y cron supervisor


# کپی composer.json اول برای cache بهتر
COPY composer.json composer.lock /var/www/html/
RUN composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist --no-scripts

# کپی بقیه پروژه
COPY . /var/www/html

# Fix permissions
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache \
    && chmod -R 755 /var/www/html/storage /var/www/html/bootstrap/cache

# فایل Supervisor
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# فایل کرون برای Laravel Scheduler
COPY docker/cron-service-cache /etc/cron.d/cron-service-cache
RUN chmod 0644 /etc/cron.d/cron-service-cache && crontab /etc/cron.d/cron-service-cache \
    && touch /var/log/cron-service-cache.log

# CMD اجرا با Supervisor (php-fpm + cron)
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]

