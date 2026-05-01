FROM php:8.3-cli-bookworm

ENV DEBIAN_FRONTEND=noninteractive

RUN apt-get update \
    && apt-get install -y --no-install-recommends $PHPIZE_DEPS ldap-utils slapd libsqlite3-dev pkg-config curl \
    && docker-php-ext-install -j"$(nproc)" pdo_sqlite \
    && apt-get purge -y --auto-remove $PHPIZE_DEPS \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app/backend

COPY backend /app/backend
RUN mkdir -p /app/storage/csv

EXPOSE 8080

CMD ["sh", "-c", "php -S 0.0.0.0:${BACKEND_PORT:-8080} -t /app/backend/public /app/backend/public/index.php"]
