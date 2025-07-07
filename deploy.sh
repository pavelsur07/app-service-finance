#!/bin/bash

# =============================
# Настройки
# =============================

SERVER_USER=root                # Имя пользователя SSH
SERVER_HOST=217.198.13.171      # IP или домен сервера
SERVER_DIR=/srv/2bstock-app     # Путь к проекту на сервере
ENV_FILE=.env.prod            # Локальный файл с env

# =======================================
# 📦 Шаг 1: Создаём архив без vendor/ и var/
# =======================================
echo "🚀 Создаю чистый архив проекта без vendor/, var/ и node_modules/"

zip -r app.zip . \
  -x "vendor/*" \
  -x "var/*" \
  -x "node_modules/*" \
  -x "app.zip"

echo "✅ Архив создан: app.zip"

# =======================================
# 📤 Шаг 2: Загружаем архив и .env.prod
# =======================================
echo "🚀 Загружаю проект и .env.prod на сервер..."

scp app.zip $SERVER_USER@$SERVER_HOST:$SERVER_DIR
scp $ENV_FILE $SERVER_USER@$SERVER_HOST:$SERVER_DIR

echo "✅ Загрузка завершена"

# =======================================
# 🗂️ Шаг 3: Разворачиваем на сервере
# =======================================
echo "🚀 Подключаюсь по SSH и разворачиваю проект..."

ssh $SERVER_USER@$SERVER_HOST << EOF
  set -e  # Останавливаем скрипт при любой ошибке

  cd $SERVER_DIR

  echo "🔍 Проверка и установка unzip, если нужно..."
  if ! command -v unzip &> /dev/null; then
    sudo apt-get update && sudo apt-get install -y unzip
  fi

  echo "🗑️ Удаляю старую папку current..."
  rm -rf ./current

  echo "📂 Создаю новую папку current и распаковываю проект..."
  mkdir -p ./current
  unzip -o app.zip -d ./current
  rm app.zip

  echo "📄 Копирую .env.prod внутрь каталога site/"
  cp $ENV_FILE ./current/site/.env

  echo "✅ Структура после распаковки:"
  ls -la ./current

  echo "🔍 Проверяю что папка site существует..."
  if [ ! -d "./current/site" ]; then
    echo "❌ ОШИБКА: Папка 'site' не найдена внутри ./current!"
    exit 1
  fi

  echo "🐳 Запускаю docker-compose.prod.yml..."
  cd ./current
  docker compose -f docker-compose.prod.yml --env-file ./site/.env up -d --build

  echo "✅ Деплой и запуск завершены!"
EOF

echo "🚀 Всё готово! Проверь контейнеры: docker ps"
