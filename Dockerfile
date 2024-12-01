# Use the official PHP 8.3 image
FROM php:8.3-cli

# Install required dependencies
RUN apt-get update && apt-get install -y \
    git \
    build-essential \
    autoconf \
    automake \
    libtool \
    pkg-config \
    gdb \
    valgrind \
    vim \
    && apt-get clean

# Ensure phpize and PHP source are available
RUN docker-php-source extract

# Add a non-root user for development
RUN useradd -ms /bin/bash developer

# Fix permissions for the PHP extensions directory
RUN chown -R developer:developer /usr/local/lib/php/extensions

# Switch to the non-root user for the development phase
USER developer

# Set the default working directory
WORKDIR /usr/src/php-extension

# Expose environment variables for debugging
ENV PHP_INI_DIR=/usr/local/etc/php \
    PATH=$PATH:/usr/local/bin

CMD ["bash"]
