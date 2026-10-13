# PHP CLI with OpenSSL's gost engine — for the GOST R 34.10-2012 signing tests.
#
#   docker build -t php-pdf-gost docker/gost
#   docker run --rm -v "$PWD:/app" -w /app php-pdf-gost vendor/bin/phpunit tests/Pdf/Signature
#
# The engine is built from source (alpine does not package it for OpenSSL 3)
# against the same OpenSSL the PHP image links, or it would not load.
ARG PHP_VERSION=8.4
FROM php:${PHP_VERSION}-cli-alpine

# Pinned: the engine has no release that builds against OpenSSL 3.x; bump
# deliberately.
ARG GOST_ENGINE_COMMIT=c13eea2114d658e29253dedfd31d17c71fcb730c
RUN apk add --no-cache --virtual .build cmake make gcc g++ git openssl-dev linux-headers musl-dev \
    && git init /tmp/gost \
    && cd /tmp/gost \
    && git remote add origin https://github.com/gost-engine/engine.git \
    && git fetch --depth 1 origin "$GOST_ENGINE_COMMIT" \
    && git checkout --quiet FETCH_HEAD \
    && git submodule update --init --recursive --depth 1 \
    && mkdir build && cd build \
    && cmake -DCMAKE_BUILD_TYPE=Release -DOPENSSL_ROOT_DIR=/usr -DOPENSSL_ENGINES_DIR=/usr/lib/engines-3 .. \
    && make -j"$(nproc)" \
    && cp bin/gost.so /usr/lib/engines-3/gost.so \
    && cd / && rm -rf /tmp/gost \
    && apk del .build \
    && apk add --no-cache openssl git unzip

COPY enable-gost-engine.sh /usr/local/bin/enable-gost-engine.sh
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
RUN chmod +x /usr/local/bin/enable-gost-engine.sh && enable-gost-engine.sh \
    && openssl engine gost -t \
    && php -r 'exit(in_array("md_gost12_256", openssl_get_md_methods(), true) ? 0 : 1);'
