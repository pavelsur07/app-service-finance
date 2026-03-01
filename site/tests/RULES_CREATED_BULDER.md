# Entity Builder Standard v2.0 — Testing

> **Для кого:** Разработчики + AI-ассистенты  
> **Контекст:** Быстрые детерминированные тесты без магии и случайности

---

## ⚡ Quick Start Checklist

Используй перед созданием нового Builder:

- [ ] ✅ Файл в `tests/Builders/<Module>/<Entity>Builder.php`
- [ ] ✅ `final class` + `declare(strict_types=1)`
- [ ] ✅ Конструктор `private`
- [ ] ✅ Фабрика `public static function a<Entity>()`
- [ ] ✅ Все `with*()` через `clone` (иммутабельность)
- [ ] ✅ Дефолты в `public const DEFAULT_*`
- [ ] ✅ Валидация входных данных (InvalidArgumentException)
- [ ] ✅ Детерминированные значения (НЕТ random/faker)

---

## 📖 Глоссарий (простыми словами)

| Термин | Что это значит |
|--------|----------------|
| **Builder** | Класс для быстрого создания тестовых Entity с настраиваемыми полями |
| **Fluent API** | Цепочка методов: `UserBuilder::aUser()->withEmail('test@test.com')->build()` |
| **Иммутабельность** | Каждый `with*()` возвращает новую копию, не меняет оригинал |
| **Детерминизм** | Одинаковые входные данные → одинаковый результат (без random) |
| **Happy path** | Валидная сущность по умолчанию, которая пройдет все проверки |
| **Preset** | Предустановленное состояние (например `asAdmin()`, `asCompanyOwner()`) |

---

## 🎯 Принципы (приоритеты)

### 🔴 КРИТИЧНО: Иммутабельность builder

**Правило:** Каждый `with*()` возвращает `clone`, никогда не меняет `$this`

```php
// ✅ ПРАВИЛЬНО
public function withEmail(string $email): self
{
    $clone = clone $this;
    $clone->email = $email;
    return $clone;
}

// ❌ НЕПРАВИЛЬНО
public function withEmail(string $email): self
{
    $this->email = $email;  // ❌ Мутация!
    return $this;
}
```

**Почему:** Избегаем side effects в тестах, один builder можно переиспользовать

---

### 🟡 Важно: Детерминизм

**Правило:** Никаких случайных значений, все фиксировано

```php
// ✅ ПРАВИЛЬНО
public const DEFAULT_USER_ID = '550e8400-e29b-41d4-a716-446655440000';
public const DEFAULT_EMAIL = 'test-user@example.com';

// ❌ НЕПРАВИЛЬНО
private string $id;

public function __construct()
{
    $this->id = Uuid::uuid4()->toString();  // ❌ Каждый раз новый!
    $this->email = 'user-' . rand() . '@test.com';  // ❌ Случайный!
}
```

**Почему:** Тесты должны быть воспроизводимыми

---

### 🟢 Производительность: Без инфраструктуры

- Builder не использует БД
- Builder не использует контейнер
- Builder не использует внешние сервисы
- Только чистое создание объекта

---

## 🚫 Запретные зоны (НЕ использовать в Builder)

```
Doctrine EntityManager    ❌ Builder не ходит в БД
Symfony Container         ❌ Builder не знает про DI
Faker/Random генераторы   ❌ Только детерминированные значения
HTTP клиенты              ❌ Только чистые объекты
```

---

## 📁 Структура и расположение

### Где живёт Builder

```
tests/Builders/<Module>/<Entity>Builder.php
```

Примеры:
```
tests/Builders/User/UserBuilder.php
tests/Builders/Sales/OrderBuilder.php
tests/Builders/Inventory/ProductBuilder.php
```

### Namespace

```php
namespace App\Tests\Builders\<Module>;
```

---

## 🔄 Жизненный цикл Builder

```
┌──────────────┐
│ ::a<Entity>()│  1. Статическая фабрика
└──────┬───────┘     создает builder с дефолтами
       │
       ▼
┌──────────────┐
│  ->with*()   │  2. Настройка через fluent API
│  ->with*()   │     (каждый метод возвращает clone)
│  ->with*()   │
└──────┬───────┘
       │
       ▼
┌──────────────┐
│  ->build()   │  3. Создание финальной Entity
└──────────────┘
```

**Ключевое:**
- Фабрика создает builder (не entity!)
- with* возвращают новый builder (clone)
- build() создает финальную entity

---

## 🏗️ Анатомия Builder (обязательная структура)

```php
final class UserBuilder
{
    // 1. Константы дефолтов
    public const DEFAULT_USER_ID = '...';
    public const DEFAULT_EMAIL = 'test@example.com';
    
    // 2. Константы ограничений (опционально)
    private const ALLOWED_ROLES = ['ROLE_USER', 'ROLE_ADMIN'];
    
    // 3. Поля builder (типизированные)
    private string $id;
    private string $email;
    private array $roles;
    
    // 4. Приватный конструктор с дефолтами
    private function __construct()
    {
        $this->id = self::DEFAULT_USER_ID;
        $this->email = self::DEFAULT_EMAIL;
        $this->roles = ['ROLE_USER'];
    }
    
    // 5. Статическая фабрика
    public static function aUser(): self
    {
        return new self();
    }
    
    // 6. Fluent setters (через clone)
    public function withId(string $id): self
    {
        $clone = clone $this;
        $clone->id = $id;
        return $clone;
    }
    
    // 7. Валидирующие setters (опционально)
    public function withRoles(array $roles): self
    {
        $invalid = array_diff($roles, self::ALLOWED_ROLES);
        if ($invalid !== []) {
            throw new InvalidArgumentException(
                'Invalid roles: ' . implode(', ', $invalid)
            );
        }
        
        $clone = clone $this;
        $clone->roles = $roles;
        return $clone;
    }
    
    // 8. Presets (опционально)
    public function asAdmin(): self
    {
        return $this->withRoles(['ROLE_ADMIN', 'ROLE_USER']);
    }
    
    // 9. Метод build
    public function build(): User
    {
        $user = new User($this->id, new \DateTimeImmutable());
        $user->setEmail($this->email);
        $user->setRoles($this->roles);
        
        return $user;
    }
}
```

---

## 📋 Эталонные примеры

### 1️⃣ Простой Builder (минимум)

```php
<?php

declare(strict_types=1);

namespace App\Tests\Builders\Sales;

use App\Sales\Entity\Order;

final class OrderBuilder
{
    public const DEFAULT_ORDER_ID = 1001;
    public const DEFAULT_COMPANY_ID = '550e8400-e29b-41d4-a716-446655440000';
    public const DEFAULT_AMOUNT = 10000;
    
    private int $id;
    private string $companyId;
    private int $amount;
    
    private function __construct()
    {
        $this->id = self::DEFAULT_ORDER_ID;
        $this->companyId = self::DEFAULT_COMPANY_ID;
        $this->amount = self::DEFAULT_AMOUNT;
    }
    
    public static function anOrder(): self
    {
        return new self();
    }
    
    public function withId(int $id): self
    {
        $clone = clone $this;
        $clone->id = $id;
        return $clone;
    }
    
    public function withCompanyId(string $companyId): self
    {
        $clone = clone $this;
        $clone->companyId = $companyId;
        return $clone;
    }
    
    public function withAmount(int $amount): self
    {
        $clone = clone $this;
        $clone->amount = $amount;
        return $clone;
    }
    
    public function build(): Order
    {
        $order = new Order(
            companyId: $this->companyId,
            customerId: 1,
            amount: $this->amount,
            createdByUserId: '00000000-0000-0000-0000-000000000000'
        );
        
        // Используем рефлексию для установки ID (если нет setter)
        $reflection = new \ReflectionClass($order);
        $property = $reflection->getProperty('id');
        $property->setAccessible(true);
        $property->setValue($order, $this->id);
        
        return $order;
    }
}
```

---

### 2️⃣ Builder с валидацией

```php
final class UserBuilder
{
    public const DEFAULT_USER_ID = '550e8400-e29b-41d4-a716-446655440000';
    public const DEFAULT_EMAIL = 'test-user@example.com';
    
    private const ALLOWED_ROLES = [
        'ROLE_USER',
        'ROLE_ADMIN',
        'ROLE_COMPANY_OWNER',
    ];
    
    private string $id;
    private string $email;
    /** @var list<string> */
    private array $roles;
    
    private function __construct()
    {
        $this->id = self::DEFAULT_USER_ID;
        $this->email = self::DEFAULT_EMAIL;
        $this->roles = ['ROLE_USER'];
    }
    
    public static function aUser(): self
    {
        return new self();
    }
    
    public function withEmail(string $email): self
    {
        $clone = clone $this;
        $clone->email = $email;
        return $clone;
    }
    
    public function withRoles(array $roles): self
    {
        // ✅ Валидация входных данных
        $invalid = array_diff($roles, self::ALLOWED_ROLES);
        if ($invalid !== []) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Invalid roles: %s. Allowed: %s',
                    implode(', ', $invalid),
                    implode(', ', self::ALLOWED_ROLES)
                )
            );
        }
        
        $clone = clone $this;
        $clone->roles = $roles;
        return $clone;
    }
    
    public function build(): User
    {
        $user = new User($this->id, new \DateTimeImmutable('2024-01-01'));
        $user->setEmail($this->email);
        $user->setRoles($this->roles);
        
        return $user;
    }
}
```

---

### 3️⃣ Builder с Presets

```php
final class UserBuilder
{
    // ... константы и поля ...
    
    public static function aUser(): self
    {
        return new self();
    }
    
    // ✅ Preset для админа
    public function asAdmin(): self
    {
        return $this
            ->withRoles(['ROLE_ADMIN', 'ROLE_USER'])
            ->withEmail('admin@example.com');
    }
    
    // ✅ Preset для владельца компании
    public function asCompanyOwner(): self
    {
        return $this
            ->withRoles(['ROLE_COMPANY_OWNER', 'ROLE_USER'])
            ->withEmail('owner@example.com');
    }
    
    // ✅ Preset для гостя
    public function asGuest(): self
    {
        return $this
            ->withRoles(['ROLE_USER'])
            ->withEmail('guest@example.com');
    }
}
```

**Использование:**

```php
// Быстрое создание админа
$admin = UserBuilder::aUser()->asAdmin()->build();

// Админ с кастомным email
$admin = UserBuilder::aUser()
    ->asAdmin()
    ->withEmail('custom-admin@example.com')
    ->build();
```

---

### 4️⃣ Builder с генерацией вариаций (детерминированно)

```php
final class UserBuilder
{
    // ...
    
    /**
     * Создает детерминированный email на основе индекса
     * 
     * @param int $index 1, 2, 3...
     */
    public function withIndex(int $index): self
    {
        return $this->withEmail(sprintf('test-user-%d@example.com', $index));
    }
}
```

**Использование в тестах:**

```php
// Создание 10 уникальных пользователей детерминированно
$users = [];
for ($i = 1; $i <= 10; $i++) {
    $users[] = UserBuilder::aUser()
        ->withIndex($i)
        ->build();
}

// Каждый раз одинаковые email:
// test-user-1@example.com
// test-user-2@example.com
// ...
```

---

### 5️⃣ Builder с датами (детерминированно)

```php
final class OrderBuilder
{
    public const DEFAULT_CREATED_AT = '2024-01-01 10:00:00';
    
    private \DateTimeImmutable $createdAt;
    
    private function __construct()
    {
        $this->createdAt = new \DateTimeImmutable(self::DEFAULT_CREATED_AT);
    }
    
    public function withCreatedAt(\DateTimeImmutable $createdAt): self
    {
        $clone = clone $this;
        $clone->createdAt = $createdAt;
        return $clone;
    }
    
    public function createdYesterday(): self
    {
        return $this->withCreatedAt(
            (new \DateTimeImmutable())->modify('-1 day')
        );
    }
    
    public function createdLastWeek(): self
    {
        return $this->withCreatedAt(
            (new \DateTimeImmutable())->modify('-1 week')
        );
    }
}
```

---

## ❌ Частые ошибки (антипаттерны)

### 1. Мутация вместо клонирования

```php
// ❌ НЕПРАВИЛЬНО
public function withEmail(string $email): self
{
    $this->email = $email;  // ❌ Меняет оригинальный builder!
    return $this;
}

// ✅ ПРАВИЛЬНО
public function withEmail(string $email): self
{
    $clone = clone $this;
    $clone->email = $email;
    return $clone;
}
```

**Проблема:** Переиспользование builder даст неожиданные результаты

```php
// ❌ При мутации
$baseUser = UserBuilder::aUser();
$user1 = $baseUser->withEmail('user1@test.com')->build();
$user2 = $baseUser->withEmail('user2@test.com')->build();
// $user1 тоже изменился! 😱

// ✅ При клонировании
$baseUser = UserBuilder::aUser();
$user1 = $baseUser->withEmail('user1@test.com')->build();
$user2 = $baseUser->withEmail('user2@test.com')->build();
// $user1 не изменился ✅
```

---

### 2. Случайные значения в дефолтах

```php
// ❌ НЕПРАВИЛЬНО
private function __construct()
{
    $this->id = Uuid::uuid4()->toString();  // ❌
    $this->createdAt = new \DateTimeImmutable();  // ❌ now()
    $this->email = 'user-' . mt_rand() . '@test.com';  // ❌
}

// ✅ ПРАВИЛЬНО
public const DEFAULT_USER_ID = '550e8400-e29b-41d4-a716-446655440000';
public const DEFAULT_EMAIL = 'test-user@example.com';
public const DEFAULT_CREATED_AT = '2024-01-01 10:00:00';

private function __construct()
{
    $this->id = self::DEFAULT_USER_ID;
    $this->email = self::DEFAULT_EMAIL;
    $this->createdAt = new \DateTimeImmutable(self::DEFAULT_CREATED_AT);
}
```

**Решение:** Фиксированные константы

---

### 3. Публичный конструктор

```php
// ❌ НЕПРАВИЛЬНО
public function __construct()  // ❌ public
{
    $this->id = self::DEFAULT_USER_ID;
}

// Можно создать напрямую:
$builder = new UserBuilder();  // ❌ Обходит статическую фабрику

// ✅ ПРАВИЛЬНО
private function __construct()  // ✅ private
{
    $this->id = self::DEFAULT_USER_ID;
}

// Единственный способ создания:
$builder = UserBuilder::aUser();  // ✅
```

**Решение:** Конструктор всегда `private`

---

### 4. Отсутствие валидации

```php
// ❌ НЕПРАВИЛЬНО (пропускает невалидные данные)
public function withStatus(string $status): self
{
    $clone = clone $this;
    $clone->status = $status;  // ❌ Любая строка
    return $clone;
}

// В тесте можно создать невалидную entity:
$order = OrderBuilder::anOrder()
    ->withStatus('INVALID_STATUS')  // ❌ Должно быть 'pending', 'completed', etc
    ->build();

// ✅ ПРАВИЛЬНО
private const ALLOWED_STATUSES = ['pending', 'processing', 'completed', 'cancelled'];

public function withStatus(string $status): self
{
    if (!in_array($status, self::ALLOWED_STATUSES, true)) {
        throw new \InvalidArgumentException(
            sprintf('Invalid status "%s". Allowed: %s', 
                $status, 
                implode(', ', self::ALLOWED_STATUSES)
            )
        );
    }
    
    $clone = clone $this;
    $clone->status = $status;
    return $clone;
}
```

**Решение:** Валидация там, где есть ограничения

---

### 5. Build() додумывает данные

```php
// ❌ НЕПРАВИЛЬНО
public function build(): User
{
    $user = new User($this->id, new \DateTimeImmutable());
    
    // ❌ Builder "додумывает" значения
    if ($this->email === null) {
        $this->email = 'auto-generated@test.com';
    }
    
    $user->setEmail($this->email);
    return $user;
}

// ✅ ПРАВИЛЬНО
private function __construct()
{
    // ✅ Все значения заданы в конструкторе
    $this->email = self::DEFAULT_EMAIL;
}

public function build(): User
{
    // ✅ Просто использует то, что есть
    $user = new User($this->id, new \DateTimeImmutable());
    $user->setEmail($this->email);
    return $user;
}
```

**Решение:** Все дефолты в конструкторе, build() не додумывает

---

## 🤖 AI-подсказки

### Если видишь в коде:

| Паттерн | Действие |
|---------|----------|
| `return $this;` в `with*()` без `clone` | ❌ ОШИБКА: добавить `$clone = clone $this;` |
| `Uuid::uuid4()` в дефолтах | ❌ ОШИБКА: использовать константу |
| `new \DateTimeImmutable()` без параметра | ❌ ОШИБКА: задать фиксированную дату |
| `public function __construct()` | ❌ ОШИБКА: сделать `private` |
| `withStatus()` без валидации enum | ⚠️ ПРЕДУПРЕЖДЕНИЕ: добавить проверку |
| Использование Faker/Random | ❌ ОШИБКА: заменить на детерминированные константы |
| Builder использует EntityManager | ❌ ОШИБКА: Builder не должен знать про БД |

---

## 📊 Быстрая справка: Структура методов

| Метод | Назначение | Обязательно |
|-------|------------|-------------|
| `private __construct()` | Инициализация дефолтов | ✅ Да |
| `public static function a<Entity>()` | Статическая фабрика | ✅ Да |
| `public function with*(): self` | Fluent setter | ✅ Да |
| `public function as*(): self` | Preset состояние | ⚪ Опционально |
| `public function build(): <Entity>` | Создание entity | ✅ Да |

---

## 📝 Definition of Done (перед созданием Builder)

```
✅ final class + declare(strict_types=1)
✅ Namespace: App\Tests\Builders\<Module>
✅ Конструктор private
✅ Есть static a<Entity>()
✅ Все with*() через clone
✅ Дефолты в public const DEFAULT_*
✅ Валидация в with*() где нужно
✅ build() использует реальный конструктор Entity
✅ Нет random/faker значений
✅ Нет зависимостей от инфраструктуры
```

---

## 🎓 Шаблоны для быстрого старта

### Копипаст шаблон (минимум)

```php
<?php

declare(strict_types=1);

namespace App\Tests\Builders\<Module>;

use App\<Module>\Entity\<Entity>;

final class <Entity>Builder
{
    public const DEFAULT_ID = 1;
    // public const DEFAULT_... = '...';
    
    private int $id;
    // private string $...;
    
    private function __construct()
    {
        $this->id = self::DEFAULT_ID;
        // $this->... = self::DEFAULT_...;
    }
    
    public static function a<Entity>(): self
    {
        return new self();
    }
    
    public function withId(int $id): self
    {
        $clone = clone $this;
        $clone->id = $id;
        return $clone;
    }
    
    public function build(): <Entity>
    {
        return new <Entity>(
            // передай обязательные аргументы конструктора
        );
    }
}
```

---

### Копипаст шаблон (с валидацией)

```php
<?php

declare(strict_types=1);

namespace App\Tests\Builders\<Module>;

use App\<Module>\Entity\<Entity>;

final class <Entity>Builder
{
    public const DEFAULT_STATUS = 'pending';
    
    private const ALLOWED_STATUSES = ['pending', 'active', 'completed'];
    
    private string $status;
    
    private function __construct()
    {
        $this->status = self::DEFAULT_STATUS;
    }
    
    public static function a<Entity>(): self
    {
        return new self();
    }
    
    public function withStatus(string $status): self
    {
        if (!in_array($status, self::ALLOWED_STATUSES, true)) {
            throw new \InvalidArgumentException(
                sprintf('Invalid status "%s". Allowed: %s', 
                    $status, 
                    implode(', ', self::ALLOWED_STATUSES)
                )
            );
        }
        
        $clone = clone $this;
        $clone->status = $status;
        return $clone;
    }
    
    public function build(): <Entity>
    {
        $entity = new <Entity>();
        $entity->setStatus($this->status);
        return $entity;
    }
}
```

---

## 💡 Использование в тестах

### Базовое использование

```php
class OrderTest extends TestCase
{
    public function testOrderCreation(): void
    {
        // ✅ Простое создание с дефолтами
        $order = OrderBuilder::anOrder()->build();
        
        $this->assertSame(10000, $order->getAmount());
    }
    
    public function testOrderWithCustomAmount(): void
    {
        // ✅ Кастомизация через fluent API
        $order = OrderBuilder::anOrder()
            ->withAmount(50000)
            ->build();
        
        $this->assertSame(50000, $order->getAmount());
    }
}
```

---

### Переиспользование базового builder

```php
class UserTest extends TestCase
{
    public function testMultipleUsers(): void
    {
        // ✅ Базовый builder можно переиспользовать
        $baseUser = UserBuilder::aUser()->withRoles(['ROLE_USER']);
        
        $user1 = $baseUser->withEmail('user1@test.com')->build();
        $user2 = $baseUser->withEmail('user2@test.com')->build();
        $user3 = $baseUser->withEmail('user3@test.com')->build();
        
        // $user1, $user2, $user3 независимы благодаря clone
        $this->assertNotSame($user1->getEmail(), $user2->getEmail());
    }
}
```

---

### Использование presets

```php
class PermissionTest extends TestCase
{
    public function testAdminCanAccessBackoffice(): void
    {
        // ✅ Preset для быстрого создания
        $admin = UserBuilder::aUser()->asAdmin()->build();
        
        $this->assertTrue($this->canAccess($admin, '/backoffice'));
    }
    
    public function testGuestCannotAccessBackoffice(): void
    {
        $guest = UserBuilder::aUser()->asGuest()->build();
        
        $this->assertFalse($this->canAccess($guest, '/backoffice'));
    }
}
```

---

## 🔗 Связанные документы

- [ ] Module Development Standard v2.2
- [ ] Testing Best Practices
- [ ] Entity Design Guidelines

---

**Версия:** 2.0  
**Обновлено:** 2025  
**Для вопросов:** См. `UserBuilder` как эталонный пример
