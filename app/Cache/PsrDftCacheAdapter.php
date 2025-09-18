<?php
declare(strict_types=1);

namespace App\Cache;

use Psr\SimpleCache\SimpleCacheInterface;
use Psr\SimpleCache\InvalidArgumentException;
use App\Interfaces\CacheManagerInterface;
use App\Interfaces\LoggerInterface;

class PsrDftCacheAdapter implements SimpleCacheInterface
{
    private CacheManagerInterface $cacheManager;
    private LoggerInterface $logger;

    public function __construct(CacheManagerInterface $cacheManager, LoggerInterface $logger)
    {
        $this->cacheManager = $cacheManager;
        $this->logger = $logger;
    }

    private function parseKey(string $key): array
    {
        if (!str_contains($key, '|')) {
            throw new InvalidArgumentException('Ключ должен быть в формате "query|labelsJson"');
        }
        [$query, $labelsJson] = explode('|', $key, 2);
        return ['query' => $query, 'labelsJson' => $labelsJson];
    }

    public function get(string $key, mixed $default = null): mixed
    {
        try {
            $parsed = $this->parseKey($key);
            $result = $this->cacheManager->loadFromCache($parsed['query'], $parsed['labelsJson']);
            return $result ?? $default;
        } catch (InvalidArgumentException $e) {
            $this->logger->error("Неверный формат ключа PSR: $key - " . $e->getMessage());
            return $default;
        } catch (\Exception $e) {
            $this->logger->error("Ошибка получения из DFT кэша для ключа $key: " . $e->getMessage());
            return $default;
        }
    }

    public function set(string $key, mixed $value, ?\DateIntervalInterface $ttl = null): bool
    {
        try {
            $parsed = $this->parseKey($key);
            $config = [];
            if ($ttl !== null) {
                $config['ttl'] = $ttl->getTimestamp();
            }
            $this->cacheManager->saveToCache($parsed['query'], $parsed['labelsJson'], $value, $config);
            $this->logger->info("Значение сохранено в DFT кэш для ключа: $key");
            return true;
        } catch (InvalidArgumentException $e) {
            $this->logger->error("Неверный формат ключа PSR: $key - " . $e->getMessage());
            return false;
        } catch (\Exception $e) {
            $this->logger->error("Ошибка сохранения в DFT кэш для ключа $key: " . $e->getMessage());
            return false;
        }
    }

    public function delete(string $key): bool
    {
        try {
            $parsed = $this->parseKey($key);
            // Для удаления используем прямой доступ к БД через cacheManager, но поскольку нет delete, симулируем через shouldRecreateCache = true или просто возвращаем false
            // Альтернатива: добавить метод delete в CacheManagerInterface позже, но пока отметим как не реализован полностью
            $this->logger->warning("Удаление DFT кэша не полностью поддерживается в адаптере для ключа: $key");
            return $this->cacheManager->checkCacheExists($parsed['query'], $parsed['labelsJson']);
            // TODO: Реализовать реальное удаление через SQL в будущем
        } catch (InvalidArgumentException $e) {
            $this->logger->error("Неверный формат ключа PSR: $key - " . $e->getMessage());
            return false;
        }
    }

    public function clear(): bool
    {
        try {
            $this->cacheManager->cleanupOldEntries(0); // Очистка всего
            $this->logger->info("DFT кэш полностью очищен через PSR адаптер");
            return true;
        } catch (\Exception $e) {
            $this->logger->error("Ошибка очистки DFT кэша: " . $e->getMessage());
            return false;
        }
    }

    public function getMultiple(iterable $keys, array $default = []): array
    {
        $results = [];
        foreach ($keys as $key) {
            $results[$key] = $this->get($key, $default[$key] ?? null);
        }
        return $results;
    }

    public function setMultiple(iterable $values, ?\DateIntervalInterface $ttl = null): bool
    {
        $success = true;
        foreach ($values as $key => $value) {
            if (!$this->set((string)$key, $value, $ttl)) {
                $success = false;
            }
        }
        return $success;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        $success = true;
        foreach ($keys as $key) {
            if (!$this->delete((string)$key)) {
                $success = false;
            }
        }
        return $success;
    }

    public function has(string $key): bool
    {
        try {
            $parsed = $this->parseKey($key);
            return $this->cacheManager->checkCacheExists($parsed['query'], $parsed['labelsJson']);
        } catch (InvalidArgumentException $e) {
            $this->logger->error("Неверный формат ключа PSR: $key - " . $e->getMessage());
            return false;
        }
    }
}
?>