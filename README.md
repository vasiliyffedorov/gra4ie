# Gra4ie

## Описание

Gra4ie — это PHP-приложение для анализа метрик с дашбордов Grafana на предмет аномалий. Аномалии детектируются при выходе значений за пределы коридора, построенного на основе тренда и дискретного преобразования Фурье (DFT). Приложение работает как прокси-сервер, интегрируясь с Grafana через API, и использует кэширование на базе SQLite для оптимизации производительности.

Основные возможности:
- Получение метрик из Grafana.
- Расчет статистических показателей и построение коридоров.
- Детекция аномалий с использованием DFT и трендов.
- Кэширование результатов для ускорения запросов.
- Логирование и мониторинг производительности.

## Требования

- PHP 8.0 или выше.
- Расширения: PDO (для SQLite), curl (для HTTP-запросов).
- Grafana с доступом к API (view-only токен).
- SQLite для кэша (файловая база данных создается автоматически).

## Установка

1. Клонируйте репозиторий:
   ```
   git clone <repository-url>
   cd gra4ie
   ```

2. Скопируйте пример конфигурации:
   ```
   cp config/config.cfg.example config/config.cfg
   ```

3. Установите зависимости (если используются Composer, но в проекте их нет, так что опционально):
   ```
   composer install
   ```

## Конфигурация

Отредактируйте файл `config/config.cfg`:

- `grafana_token`: View-only токен из целевой Grafana.
- `blacklist_boards`: Список ID дашбордов для исключения (чтобы избежать анализа собственных дашбордов и зацикливания).
- `cache_path`: Путь к SQLite-файлу кэша (по умолчанию `./cache.db`).
- `log_level`: Уровень логирования (debug/info/error).

Дополнительно:
- В Grafana добавьте новый источник данных формата Prometheus по адресу `<your-ip>:9093`.
- Сгенерируйте view-only токен для этого источника и запомните ID источника (если нужно для blacklist).

## Подготовка к запуску

Перед первым запуском сервера необходимо обновить кэш метрик Grafana, чтобы избежать запросов к API при каждом обращении:

```
php bin/update_dashboards_cache.php
```

Эта команда загрузит список дашбордов и панелей из Grafana и сохранит их в кэше SQLite. Рекомендуется запускать её периодически (например, раз в день), если дашборды изменяются.

## Запуск

Запустите встроенный PHP-сервер в корневой директории:
```
php -S 0.0.0.0:9093
```

Сервер будет доступен на порту 9093.

## Использование

1. В Grafana добавьте новую панель.
2. Выберите добавленный источник Prometheus (`<your-ip>:9093`).
3. В запросе выберите дашборд из списка.
4. Приложение вернет метрики с наложенным коридором аномалий.

Пример запроса в Grafana: Выберите метрику, и Gra4ie автоматически обработает данные, добавив corridor и anomaly detection.

Для тестирования: Проверьте логи в `./logs/` (если настроено) и кэш в указанном пути. Для PSR-16 кэша используйте `php bin/test_psr_cache.php`.

## Архитектура

- **Входная точка**: `index.php` — обрабатывает HTTP-запросы от Grafana.
- **DI Container**: `app/DI/Container.php` — управление зависимостями.
- **Клиенты**: `app/Clients/GrafanaProxyClient.php` — взаимодействие с Grafana API.
- **Процессоры**:
  - `StatsCalculator.php` — расчет статистик.
  - `FourierTransformer.php` и `DFTProcessor.php` — DFT для трендов.
  - `CorridorBuilder.php` — построение коридоров.
  - `AnomalyDetector.php` — детекция аномалий.
- **Кэш**: `app/Cache/SQLiteCacheManager.php` — управление кэшем на SQLite. Поддержка PSR-16 через адаптер `PsrDftCacheAdapter.php`.
- **Утилиты**: `Logger.php` — логирование, `PerformanceMonitor.php` — мониторинг.

- `AutoTunePeriodCalculator.php` — класс для автоматической настройки параметра `historical_period_days` на основе DFT-анализа исторических данных метрики. Использует интерполяцию, детренд и поиск доминирующего периода для оптимизации длины исторического периода. Интерфейс: `calculateOptimalPeriod(array $data): float`, где $data - timestamp => value.

Приложение использует интерфейсы для абстракции (например, `LoggerInterface.php`, `CacheManagerInterface.php`).

## Интеграция PSR-16 для кэша

Система кэша теперь поддерживает стандарт PSR-16 (Simple Cache) через адаптер `App\Cache\PsrDftCacheAdapter`. Это позволяет использовать стандартные интерфейсы PSR-16 для кэширования данных DFT.

### Основные особенности
- **Совместимость**: Оборачивает существующий `SQLiteCacheManager` без изменения схемы БД.
- **Формат ключей**: Ключи в формате `query|labelsJson` (например, `rate(http_requests_total{job="api-server"}[5m])|{"instance":"10.0.0.1:8080","job":"api-server"}`).
- **Сопоставление методов**:
  - `get/set`: Сопоставляется с `loadFromCache/saveToCache`.
  - `has`: Сопоставляется с `checkCacheExists`.
  - `delete`: Частично поддерживается (предупреждение, так как прямое удаление не в оригинальном интерфейсе).
  - `clear`: Использует `cleanupOldEntries(0)`.
- **Инъекция**: Используйте `\Psr\SimpleCache\SimpleCacheInterface` в DI Container.

### Пример использования
```php
$container = new \App\DI\Container($config);
$psrCache = $container->get(\Psr\SimpleCache\SimpleCacheInterface::class);

$key = $query . '|' . json_encode($labels);
$psrCache->set($key, $dftData);
$dftData = $psrCache->get($key);
```

### Тестирование
Запустите `php bin/test_psr_cache.php` для проверки функциональности PSR-16 с примерами DFT данных.

## Вклад в проект

- Форкните репозиторий.
- Создайте ветку для изменений.
- Отправьте pull request.

## Лицензия

MIT License (или укажите актуальную, если есть).


## Изменения в логгере (2025-09-18)

- Исправлена ошибка: Все вызовы несуществующего метода `Logger::warn()` заменены на `Logger::warning()` для соответствия PSR-3 стандарту.
- Затронутые файлы: DataProcessor.php (2 места), LinearTrendCalculator.php (2), StatsCalculator.php (1), SQLiteCacheDatabase.php (5), DFTProcessor.php (2), PsrDftCacheAdapter.php (1).
- В StatsCalculator.php добавлена информация о файле и строке в сообщение для сохранения контекста.

Это устраняет PHP Fatal error в DataProcessor.php:40 и связанных цепочках вызовов.
