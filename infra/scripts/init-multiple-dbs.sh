#!/bin/bash
# Создаёт все базы данных, перечисленные в POSTGRES_MULTIPLE_DATABASES.
set -e

for db in $(echo "$POSTGRES_MULTIPLE_DATABASES" | tr ',' ' '); do
    echo "Создание базы данных: $db"
    psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" <<-EOSQL
        SELECT 'CREATE DATABASE $db'
        WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = '$db')\gexec
EOSQL
done
