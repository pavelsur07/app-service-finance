#!/bin/bash

# =============================
# Настройки
# =============================

SERVER_USER=root          # Имя пользователя SSH
SERVER_HOST=217.198.13.171    # IP или домен сервера
SERVER_DIR=/srv/2bstock-app    # Путь к проекту на сервере
ENV_FILE=".env.prod"              # Локальный файл с env

# =============================
# Шаг 1: Создаём архив без vendor/ и var/
# =============================

echo "🚀 Создаём архив проекта (без vendor/ и var/)..."

zip -r app.zip . \
  -x "vendor/*" \
  -x "var/*" \
  -x "node_modules/*" \
  -x "app.zip"

# =============================
# Шаг 2: Загружаем архив и env
# =============================

echo "🚀 Загружаем архив на сервер..."

scp app.zip $SERVER_USER@$SERVER_HOST:$SERVER_DIR

echo "🚀 Загружаем файл окружения..."

scp $ENV_FILE $SERVER_USER@$SERVER_HOST:$SERVER_DIR

# =============================
# Шаг 3: Подключаемся по SSH и разворачиваем
# =============================

echo "🚀 Подключаемся на сервер и разворачиваем проект..."

ssh $SERVER_USER@$SERVER_HOST << EOF
  cd $SERVER_DIR

  echo "🔍 Проверяем unzip..."
  if ! command -v unzip &> /dev/null
  then
    echo "🛠️ unzip не найден, устанавливаем..."
    sudo apt-get update && sudo apt-get install -y unzip
  else
    echo "✅ unzip уже установлен"
  fi

  echo "🗑️ Удаляем старый код..."
  rm -rf ./current
  mkdir -p current

  echo "📦 Распаковываем проект..."
  unzip -o app.zip -d ./current
  rm app.zip

  echo "📄 Копируем файл окружения..."
  cp $ENV_FILE ./current/.env

  cd current

  echo "🐳 Собираем и запускаем docker-compose.prod.yml..."
  docker compose -f docker-compose.prod.yml --env-file .env up -d --build

  echo "✅ Деплой завершен!"
EOF

echo "🚀 Всё готово!"
