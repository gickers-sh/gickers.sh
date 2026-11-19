# syntax=docker/dockerfile:1

# ---- Stage 1: build the static codes-ssh gateway (pure Go, no CGO) ----
FROM golang:1.26-bookworm AS ssh-build
WORKDIR /src
COPY cmd/codes-ssh/go.mod cmd/codes-ssh/go.sum ./
RUN go mod download
COPY cmd/codes-ssh/ ./
RUN CGO_ENABLED=0 go build -trimpath -ldflags='-s -w' -o /out/codes-ssh .

# ---- Stage 2: runtime (FrankenPHP worker mode + git + PHP app) ----
# Debian base (glibc): most reliable combo for FFI + pcntl worker mode + git-lfs.
FROM dunglas/frankenphp:1-php8.4-bookworm AS runtime

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        git \
        git-lfs \
        gnupg \
        postgresql-client \
    && install -m 0755 -d /etc/apt/keyrings \
    && curl -fsSL https://download.docker.com/linux/debian/gpg | gpg --dearmor -o /etc/apt/keyrings/docker.gpg \
    && chmod a+r /etc/apt/keyrings/docker.gpg \
    && echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/debian bookworm stable" > /etc/apt/sources.list.d/docker.list \
    && apt-get update \
    && apt-get install -y --no-install-recommends \
        docker-ce-cli \
        docker-compose-plugin \
        docker-buildx-plugin \
    && ln -sf /usr/libexec/docker/cli-plugins/docker-compose /usr/local/bin/docker-compose \
    && git lfs install --system \
    && rm -rf /var/lib/apt/lists/*

# Extensions required by codes.sh plus the Postgres / SQLite drivers.
# (pdo_sqlite powers `soft admin db:import-sqlite`; pdo_pgsql is the runtime DB.)
RUN install-php-extensions ffi gd mbstring pcntl posix pdo_pgsql pdo_sqlite intl opcache zip

WORKDIR /app
ENV COMPOSER_ALLOW_SUPERUSER=1
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# codes-sh/termstrap is a local path repository (composer.json: ../termstrap).
# With WORKDIR /app, "../termstrap" resolves to /termstrap, so place it there.
COPY --from=termstrap . /termstrap

# Install PHP dependencies first so Docker caches this layer across source edits.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist --no-autoloader

# Application source + the freshly built Go gateway.
COPY . .
COPY --from=ssh-build /out/codes-ssh /usr/local/bin/codes-ssh
RUN ln -sf /usr/local/bin/codes-ssh /app/bin/codes-ssh \
    && composer dump-autoload --optimize --no-dev --no-scripts \
    && chmod +x bin/soft bin/console /usr/local/bin/codes-ssh

ENV APP_ENV=prod
# soft:serve prepares state and supervises the requested component(s).
# Compose overrides the command per service (--only=init|ssh|http|worker).
ENTRYPOINT ["php", "bin/soft"]
CMD ["serve"]
