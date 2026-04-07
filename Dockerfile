FROM wordpress:latest

# Install additional PHP extensions useful for plugin development
RUN apt-get update && apt-get install -y \
    less \
    vim \
    unzip \
    && rm -rf /var/lib/apt/lists/*

# Configure PHP for development
RUN cp /usr/local/etc/php/php.ini-development /usr/local/etc/php/php.ini

# Configure Mailpit as SMTP relay
RUN echo "sendmail_path = '/usr/sbin/sendmail -S mailpit:1025'" >> /usr/local/etc/php/php.ini

# Increase PHP limits for development
RUN echo "upload_max_filesize = 64M" >> /usr/local/etc/php/php.ini \
    && echo "post_max_size = 64M" >> /usr/local/etc/php/php.ini \
    && echo "memory_limit = 256M" >> /usr/local/etc/php/php.ini \
    && echo "max_execution_time = 300" >> /usr/local/etc/php/php.ini

# Enable error logging
RUN echo "log_errors = On" >> /usr/local/etc/php/php.ini \
    && echo "error_log = /var/log/php_errors.log" >> /usr/local/etc/php/php.ini
