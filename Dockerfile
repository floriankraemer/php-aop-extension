# Development container: PHP 8.3 (NTS) with everything needed to build and
# debug the extension. The whole repository is mounted at /workspace by
# docker-compose.yaml.
#
#   docker compose run --rm php bash
#   make ext test-ext test
#
# For a ZTS toolchain: docker compose build --build-arg PHP_TAG=8.3-zts-bookworm
ARG PHP_TAG=8.3-cli-bookworm
FROM php:${PHP_TAG}

RUN apt-get update \
    && DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
        $PHPIZE_DEPS \
        ca-certificates \
        gdb \
        git \
        make \
        sudo \
        unzip \
        valgrind \
        vim-tiny \
    && rm -rf /var/lib/apt/lists/*

# Composer from the official image.
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# Non-root developer matching the host user so files in the bind mount keep
# sane ownership. Override with --build-arg UID=$(id -u) GID=$(id -g).
ARG UID=1000
ARG GID=1000
RUN (getent group "${GID}" >/dev/null || groupadd -g "${GID}" developer) \
    && useradd -m -s /bin/bash -u "${UID}" -g "${GID}" developer \
    && echo "developer ALL=(ALL) NOPASSWD:ALL" > /etc/sudoers.d/developer

# Valgrind-friendly allocator for debugging: USE_ZEND_ALLOC=0 php ...
ENV COMPOSER_HOME=/home/developer/.composer \
    TEST_PHP_EXECUTABLE=/usr/local/bin/php

USER developer
WORKDIR /workspace
CMD ["bash"]
