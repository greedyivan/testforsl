FROM php:8.3-cli

RUN apt-get update && apt-get install -y --no-install-recommends \
        libpq-dev libzip-dev zip curl unzip git \
    && docker-php-ext-install pdo pdo_pgsql pcntl sockets zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# BUILD_DEV=1 устанавливает dev-зависимости (PHPUnit и др.) для запуска тестов
ARG BUILD_DEV=0

# Устанавливаем зависимости первым слоем — кэшируется, пока composer-файлы не изменятся
COPY composer.json composer.lock ./
RUN if [ "$BUILD_DEV" = "1" ]; then \
        composer install --no-scripts --no-autoloader --prefer-dist --no-interaction; \
    else \
        composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction; \
    fi

# Копируем исходный код
COPY . .

# Создаём нужные директории, выставляем права, генерируем оптимизированный автозагрузчик.
# Хук post-autoload-dump запускает `artisan package:discover`, создавая
# bootstrap/cache/packages.php. RabbitMQTopologyProvider защищён от вызова
# вне serve/queue:work, поэтому подключения к брокеру при сборке не происходит.
RUN mkdir -p storage/logs \
             storage/framework/cache/data \
             storage/framework/sessions \
             storage/framework/views \
             storage/api-docs \
             bootstrap/cache \
    && chmod -R 777 storage bootstrap/cache \
    && APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= \
       APP_ENV=production \
       DATABASE_URL=postgresql://postgres:postgres@localhost:5432/notifications \
       RABBITMQ_URL=amqp://guest:guest@localhost:5672/ \
       REDIS_URL=redis://localhost:6379/0 \
       MAIN_QUEUE=notifications \
       composer dump-autoload --optimize 2>&1

# Защита от CRLF-окончаний строк, если клон был сделан на Windows
# с core.autocrlf=true до появления .gitattributes в репозитории
RUN sed -i 's/\r$//' entrypoint.sh

EXPOSE 8000

# Встроенный HEALTHCHECK используется при деплое без docker-compose
# (Kubernetes, ECS и другие оркестраторы).
HEALTHCHECK --interval=30s --timeout=5s --start-period=60s --retries=3 \
    CMD curl -sf http://localhost:8000/health || exit 1

ENTRYPOINT ["bash", "entrypoint.sh"]
CMD ["serve"]
