FROM php:8.2-cli

# Install PostgreSQL PDO extension instead of MySQL
RUN apt-get update && apt-get install -y libpq-dev \
    && docker-php-ext-install pdo pdo_pgsql

WORKDIR /app
COPY . .

EXPOSE 8080

CMD ["php", "-S", "0.0.0.0:8080"]