Ниже — безопасный чек-лист, как освободить место в Docker **без удаления пользовательских данных (volumes, БД и т.п.)**. Команды для Linux (sudo при необходимости).

### 0) Сначала посмотреть, что занимает место

```bash
docker system df -v                # сводка по образам/контейнерам/кэшу
docker ps -a --size                # размеры контейнеров
docker images                      # список образов
```

### 1) Удалить остановленные контейнеры (данные в named volumes не трогаются)

> Важно: если вы храните что-то **внутри контейнера**, это пропадёт. В named volumes и bind-mounts — останется.

```bash
docker container prune             # удалить все остановленные контейнеры
# или мягче — только старше 24ч:
docker container prune --filter "until=24h"
```

### 2) Почистить образы

* «Безопасно» для продакшна: убрать только “висячие” (dangling) слои.

```bash
docker image prune
```

* Агрессивнее: убрать **все неиспользуемые** образы (контейнеры, которые их используют, не затронутся).

```bash
docker image prune -a
# или с порогом по времени:
docker image prune -a --filter "until=168h"   # старше 7 дней
```

### 3) Сети и build-кэш

```bash
docker network prune               # удалить неиспользуемые сети
docker builder prune               # build-кэш
docker builder prune --all         # всё, включая устаревший кэш
```

### 4) Общая «подметающая» команда (без volumes!)

```bash
docker system prune -a             # контейнеры(остановл.), образы(неисп.), сети, кэш
# ВАЖНО: без флага --volumes. Он бы удалил неиспользуемые volumes (там могут быть ваши данные).
```

---

## Про volumes (данные)

По умолчанию предыдущие команды **не трогают volumes**.
Если хотите проверить «сиротские» (неподключённые) тома, но **НЕ удалять**:

```bash
docker volume ls -f dangling=true
# Кто использует конкретный volume:
docker ps --filter "volume=<volume_name>"
```

Удаляйте volumes только если уверены:

```bash
docker volume prune    # осторожно! удалит все НЕИСПОЛЬЗУЕМЫЕ тома
```

---

## Логи контейнеров могут разрастаться

Ограничьте размер логов, это не затрагивает данные БД/томов:

1. `/etc/docker/daemon.json`

```json
{
  "log-driver": "json-file",
  "log-opts": { "max-size": "10m", "max-file": "3" }
}
```

2. Затем:

```bash
sudo systemctl restart docker
```

(Существующие огромные `*-json.log` можно аккуратно ротировать/обнулить, но лучше настроить лимиты как выше.)

---

## Быстрый вариант «разгребсти и не задеть данные»

```bash
docker system df -v
docker container prune -f
docker image prune -a -f
docker network prune -f
docker builder prune -af
# volumes не трогаем
```

Если после этого места всё ещё мало — проверьте крупные bind-mount каталоги на хосте и общий диск (`df -h`).
