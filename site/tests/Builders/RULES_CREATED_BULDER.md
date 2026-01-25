# Правила создания Entity Builder (для тестов) — стандарт проекта

Ниже правила **на 100% по образцу** текущего билдера `UserBuilder` :contentReference[oaicite:0]{index=0}.  
Цель: быстрые и **детерминированные** тестовые сущности без «магии», с валидацией входных данных и иммутабельным fluent API.

---

## 1) Назначение и границы

- Builder используется **только в тестах** (unit/functional/integration).
- Builder **не ходит** в БД, не использует контейнер, не зависит от инфраструктуры.
- Builder создает **валидную сущность по умолчанию** (happy-path), а дальше модифицируется через `with*`.

---

## 2) Расположение и нейминг

- Файл: `tests/Builders/<Module>/<Entity>Builder.php`
- Namespace: `App\Tests\Builders\<Module>`
- Класс: `final class <Entity>Builder`
- Статическая фабрика: `public static function a<Entity>(): self`

Пример: `UserBuilder::aUser()` :contentReference[oaicite:1]{index=1}

---

## 3) Иммутабельность builder’а (обязательное правило)

- Builder **иммутабелен**: каждый `with*()` возвращает **clone**.
- Запрещено менять `$this` напрямую.

Шаблон `with*`:
- `$clone = clone $this;`
- `$clone->field = ...;`
- `return $clone;`

---

## 4) Дефолты и конструктор

- Конструктор билдера **private**.
- Все дефолтные значения задаются **в private __construct()**.
- Дефолты фиксируются в `public const DEFAULT_*`.

Пример: `DEFAULT_USER_ID`, `DEFAULT_EMAIL`, `DEFAULT_CREATED_AT` :contentReference[oaicite:2]{index=2}

---

## 5) Поля builder’а

- Builder хранит **все** поля, необходимые для создания сущности.
- Типизируй свойства строго (`private string $...`, `private \DateTimeImmutable $...`).
- Для массивов — phpdoc `/** @var list<string> */` и/или строгая валидация.

---

## 6) Валидация в with* (гейт “не тащи мусор в тесты”)

- Если поле имеет ограниченный набор значений — заведи `private const ALLOWED_*`.
- В `with*` валидируй вход и кидай `InvalidArgumentException`.

Пример: `withRoles()` проверяет роли через `array_diff` :contentReference[oaicite:3]{index=3}

---

## 7) Преднастроенные состояния (optional, но как в примере)

- Разрешены методы вида `asCompanyOwner()` / `asAdmin()` — это «presets».
- Preset тоже **clone** и просто выставляет заранее определённое состояние.

---

## 8) Метод build()

- Сигнатура: `public function build(): <Entity>`
- Создание сущности:
    1) Вызываем **реальный** конструктор сущности с обязательными аргументами.
    2) Дозаполняем через setter’ы.
- Build **не** должен молча исправлять/додумывать данные.

Пример:
- `new User($this->id, $this->createdAt)`
- `setEmail()`, `setPassword()`, `setRoles()` :contentReference[oaicite:4]{index=4}

---

## 9) Детерминизм (очень важно)

- Все дефолтные даты/UUID/email должны быть **фиксированными**, а не случайными.
- Для генерации вариаций — метод вида `withIndex(int $index)` (как для email) :contentReference[oaicite:5]{index=5}

---

## 10) Мини-шаблон (копипаст)

```php
<?php

declare(strict_types=1);

namespace App\Tests\Builders\<Module>;

use App\Entity\<Entity>;
use InvalidArgumentException;

final class <Entity>Builder
{
    public const DEFAULT_ID = '...';
    // public const DEFAULT_... = '...';

    // private const ALLOWED_... = [...];

    private string $id;
    // private string $...;
    // private \DateTimeImmutable $...;

    private function __construct()
    {
        $this->id = self::DEFAULT_ID;
        // $this->... = self::DEFAULT_...;
    }

    public static function a<Entity>(): self
    {
        return new self();
    }

    public function withId(string $id): self
    {
        $clone = clone $this;
        $clone->id = $id;

        return $clone;
    }

    public function build(): <Entity>
    {
        $entity = new <Entity>($this->id /*, required ctor args */);

        // $entity->set...($this->...);

        return $entity;
    }
}
```
---

11) Чек-лист для ревью (быстро)

 final, strict_types=1, private __construct

 есть a<Entity>()

 все with* через clone

 дефолты в public const DEFAULT_*

 валидация через InvalidArgumentException там, где нужны ограничения

 build() использует реальный ctor + setter’ы, без догадок

 значения детерминированы (никаких random)
