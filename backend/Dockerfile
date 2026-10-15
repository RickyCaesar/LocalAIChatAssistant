FROM php:8.2-cli

RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libzip-dev \
        libonig-dev \
        libxml2-dev \
        tesseract-ocr \
        tesseract-ocr-ind \
        poppler-utils \
        libreoffice-writer \
        libreoffice-impress \
        libreoffice-calc \
        fonts-liberation \
        python3 \
        python3-venv \
        nodejs \
        npm \
        chromium \
    && docker-php-ext-install mbstring zip bcmath pdo_mysql pcntl \
    && npm install -g @marp-team/marp-cli@4.5.1 \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Local speech-to-text (faster-whisper, CPU). Isolated venv so it can't
# clash with system packages.
RUN python3 -m venv /opt/stt \
    && /opt/stt/bin/pip install --no-cache-dir --upgrade pip \
    && /opt/stt/bin/pip install --no-cache-dir faster-whisper "av>=14,<17"

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV COMPOSER_ALLOW_SUPERUSER=1

WORKDIR /app

RUN composer create-project laravel/laravel:^12.0 . --no-interaction --prefer-dist

COPY . .

RUN mkdir -p /app/database && touch /app/database/database.sqlite \
    && php artisan key:generate --force

# Baked as an immutable process env var so it always wins over .env
# (Laravel uses immutable dotenv: real env vars override the file).
ENV AI_API_URL=http://host.docker.internal:8642/v1/chat/completions \
    AI_MODEL=hermes-agent \
    AI_API_KEY=change-me-local-dev

RUN printf 'upload_max_filesize=0\npost_max_size=0\nmemory_limit=512M\nmax_execution_time=0\n' > /usr/local/etc/php/conf.d/uploads.ini

# STT runtime: interpreter, model cache inside the writable storage volume.
ENV STT_PYTHON=/opt/stt/bin/python \
    HF_HOME=/app/storage/app/stt-models \
    HF_HUB_DISABLE_TELEMETRY=1

EXPOSE 8000

CMD sh -c "php artisan migrate --force --no-interaction && php artisan serve --host=0.0.0.0 --port=8000"