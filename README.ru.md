# Componenta CQRS App

`componenta/cqrs-app` добавляет application-level discovery и build-time map compilation для `componenta/cqrs`.

Пакет не содержит runtime middleware, транспорты или console workers. Для этих задач устанавливайте отдельные CQRS packages.

## Установка

```bash
composer require componenta/cqrs-app
```

## Зависимости

| Зависимость | Назначение |
|---|---|
| PHP `^8.4` | Современные возможности языка и strict types. |
| `componenta/class-finder` | Class discovery и listener compiler integration. |
| `componenta/config` | Интеграция с config provider. |
| `componenta/cqrs` | Core CQRS runtime contracts и config keys. |
| `componenta/tokenizer` | Передаёт discovery объект `ClassInfo` с уже созданным reflector. |
| `psr/container` | Получение сервисов. |

## Что регистрирует пакет

| Config section | Entries |
|---|---|
| `factories` | Один `CqrsDiscoveryIndex`, один application map provider и application-aware фабрики для трёх interface локаторов. |
| `invokables` | Один `CqrsMapCompiler`. |
| `ClassFinderConfigKey::LISTENERS` | Единственный listener `CqrsDiscoveryIndex`. |
| `CompileConfigKey::LISTENER_COMPILERS` | Единственный compiler, создающий `ConfigKey::CQRS_MAP`. |

## Использование

Сначала зарегистрируйте core provider, затем application provider:

```php
return [
    new Componenta\CQRS\ConfigProvider(),
    new Componenta\CQRS\App\ConfigProvider(),
];
```

Оба пакета привязывают interface локаторов непосредственно через фабрики. Порядок провайдеров явно выбирает реализацию: `cqrs-app` заменяет три core-фабрики своими application-aware реализациями и не вызывает core-фабрики вручную. Более поздний provider приложения может намеренно выбрать другую реализацию; delegator-ы для того же requested id по-прежнему обернут результат.

Используйте discovery attributes в application code:

```php
use Componenta\CQRS\Command\Attribute\AsCommandHandler;

#[AsCommandHandler]
final readonly class PublishPostHandler
{
    public function __invoke(PublishPostCommand $command): void
    {
        // handle command
    }
}
```

## Discovery и метаданные

`CqrsDiscoveryIndex` один раз читает `ClassInfo::$reflector` для каждого класса и собирает обработчики команд и запросов, слушатели, имена известных команд и настроенные атрибуты метаданных. Он проверяет, что методы обработчиков публичные и нестатические, отклоняет конфликты, удаляет одинаковых слушателей и сортирует данные один раз в `finalize()`.

Дополнительные пакеты добавляют метаданные без изменения compiler: они дополняют `ConfigKey::COMMAND_METADATA_ATTRIBUTES` классом атрибута. Factory проверяет существование каждого класса и наличие `#[Attribute]`.

`ConfigKey::DISCOVERY_ENABLED` позволяет явно включить или выключить live overlay. По умолчанию discovery включён во всех окружениях, кроме точного `APP_ENV=production`; `test` и `staging` остаются non-production.

## Сборка для production

Если установлен `componenta/app-console`, перед запуском production выполните:

```bash
APP_ENV=development php bin/console.php app:build
```

Compiler записывает одну детерминированную CQRS map v2 в config cache приложения. Production-локаторы читают её без сканирования классов. Метаданные известной скомпилированной команды не используют reflection fallback; для неизвестной команды fallback допустим.

Старый CQRS key, неподдерживаемая версия карты или отсутствующая production map приводят к ошибке с указанием очистить cache и повторить build. При переходе с v1 удалите кеши конфигурации, discovery, старых CQRS maps, generated resolver и release fingerprint перед запуском `app:build`.

## Optional Runtime Packages

Для runtime concerns устанавливайте отдельные пакеты:

| Пакет | Что добавляет |
|---|---|
| `componenta/cqrs-policy` | Policy middleware для команд и запросов. |
| `componenta/cqrs-retry` | Retry middleware. |
| `componenta/cqrs-lock` | Resource lock middleware. |
| `componenta/cqrs-transaction-cycle` | Cycle Database transaction middleware. |
| `componenta/cqrs-transport` | Async transport middleware, contracts, serializer и worker. |
| `componenta/cqrs-transport-cycle` | Cycle Database transport implementation. |
| `componenta/cqrs-transport-console` | Symfony Console команда `cqrs:worker`. |
