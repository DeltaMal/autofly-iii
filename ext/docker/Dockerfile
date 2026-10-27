# syntax=docker/dockerfile:1
#
# Firefly III "ext" fork image. Build context = repository root:
#   docker build -f ext/docker/Dockerfile -t ghcr.io/deltamal/autofly-iii:6.7.9-ext.1 .
#
# Mirrors the official image (https://dev.azure.com/Firefly-III/_git/MainImage): runtime on
# fireflyiii/base, same entrypoint, same labels and health check. The official image unpacks a
# release zip; we build that tree here instead (composer + the v3 Vite build, as upstream's
# release workflow does) and add poppler-utils for statement PDFs.
# AGPL-3.0-or-later.

ARG BASE_IMAGE="fireflyiii/base"
ARG BASE_TAG="latest"
ARG NODE_IMAGE="node:22-bookworm-slim"

#
# 1. PHP dependencies, on the same base so the PHP version and extensions match (PHP >= 8.5).
#
FROM ${BASE_IMAGE}:${BASE_TAG} AS php-build
USER root
WORKDIR /build
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-scripts --no-autoloader --no-interaction --no-progress
COPY . .
RUN rm -rf node_modules public/build public/hot .env ext/tests/fixtures/private bootstrap/cache/*.php \
    && composer dump-autoload --no-dev --optimize --no-interaction \
    && cp .env.example .env && php artisan package:discover && rm .env

#
# 2. The v3 frontend, built exactly as upstream's release workflow builds it.
#
FROM ${NODE_IMAGE} AS assets
WORKDIR /build
COPY package.json package-lock.json ./
COPY resources/assets/v3/package.json resources/assets/v3/package.json
RUN npm ci --no-audit --no-fund
COPY resources/assets resources/assets
COPY ext/resources/assets ext/resources/assets
RUN npm run build --workspace=v3

#
# 3. Runtime: fireflyiii/base + the built app, like fireflyiii/core.
#
FROM ${BASE_IMAGE}:${BASE_TAG}

ARG version
ENV VERSION=$version
ARG isodate
ENV ISODATE=$isodate
ARG gitrevision
ENV GITREVISION=$gitrevision

ENV PHP_MEMORY_LIMIT=512M

HEALTHCHECK --start-period=5m --interval=5s --timeout=3s --retries=3 \
    CMD [ "sh", "-c", "curl --insecure --silent --location --show-error --fail http://localhost:8080$HEALTHCHECK_PATH || exit 1" ]

LABEL org.opencontainers.image.title="Firefly III (ext fork)" \
      org.opencontainers.image.description="Firefly III with bank integrations, statement auto-recon and custom periods" \
      org.opencontainers.image.source="https://github.com/DeltaMal/autofly-iii" \
      org.opencontainers.image.url="https://github.com/DeltaMal/autofly-iii/tree/ext" \
      org.opencontainers.image.licenses="AGPL-3.0-or-later" \
      org.opencontainers.image.base.name="docker.io/fireflyiii/base:latest" \
      docker.versions.source="https://github.com/firefly-iii/firefly-iii"
LABEL org.opencontainers.image.created="${ISODATE}"
LABEL org.opencontainers.image.version="${VERSION}"
LABEL org.opencontainers.image.revision="${GITREVISION}"

USER root
# poppler-utils provides pdftotext for statement import (ext/src/Statements/PdfTextExtractor).
RUN apt-get update \
    && apt-get install -y --no-install-recommends poppler-utils \
    && rm -rf /var/lib/apt/lists/*

COPY ext/docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod uga+x /usr/local/bin/entrypoint.sh \
    && echo "${VERSION:-ext}" > /var/www/counter-main.txt \
    && echo "${ISODATE:-unknown}" > /var/www/build-date-main.txt

COPY --chown=www-data:www-data --from=php-build /build $FIREFLY_III_PATH
COPY --chown=www-data:www-data --from=assets /build/public/build $FIREFLY_III_PATH/public/build
COPY --chown=www-data:www-data ext/docker/alerts.json $FIREFLY_III_PATH/resources/alerts.json
RUN chmod -R 775 $FIREFLY_III_PATH/storage

USER www-data
