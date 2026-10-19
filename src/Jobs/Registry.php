<?php
require_once __DIR__ . '/AskHandler.php';

/**
 * Maps a job type to its handler. A handler has handle(array $payload) and returns a string or JSON-encodable value.
 * An entry is a class name (built with no arguments) or a [class, method] factory called with the worker's PDO.
 */
class Registry
{
    /** Feature cards add one line each: 'type' => HandlerClass::class or [HandlerClass::class, 'factory']. */
    public const DEFAULT_MAP = [
        'ask' => [AskHandler::class, 'fromConfig'],
    ];

    /** @var array<string, string|array{0:string,1:string}> */
    private array $map;
    private ?PDO $pdo;

    public function __construct(?array $map = null, ?PDO $pdo = null)
    {
        $this->map = $map ?? self::DEFAULT_MAP;
        $this->pdo = $pdo;
    }

    /** Loads a map from a PHP file that returns an array; the file may require its handler classes. */
    public static function fromFile(string $path, ?PDO $pdo = null): self
    {
        $map = require $path;
        if (!is_array($map)) {
            throw new RuntimeException('Registry file must return an array: ' . $path);
        }
        return new self($map, $pdo);
    }

    public function has(string $type): bool
    {
        return isset($this->map[$type]);
    }

    /** Runs the handler for $type; returns its result as a string. Throws on an unknown type or a handler error. */
    public function run(string $type, array $payload): string
    {
        if (!$this->has($type)) {
            throw new RuntimeException('Unknown job type: ' . $type);
        }
        $result = $this->handler($type)->handle($payload);
        if ($result === null) {
            return '';
        }
        return is_string($result) ? $result : json_encode($result, JSON_UNESCAPED_SLASHES);
    }

    private function handler(string $type): object
    {
        $entry = $this->map[$type];
        if (is_array($entry)) {
            [$class, $method] = $entry + [null, null];
            if (!is_string($class) || !is_string($method) || !class_exists($class) || !method_exists($class, $method)) {
                throw new RuntimeException('Bad handler factory for job type ' . $type);
            }
            if ($this->pdo === null) {
                throw new RuntimeException('Job type ' . $type . ' needs the database');
            }
            $handler = $class::$method($this->pdo);
        } else {
            if (!is_string($entry) || !class_exists($entry)) {
                throw new RuntimeException('Bad handler for job type ' . $type);
            }
            $handler = new $entry();
        }
        if (!is_object($handler) || !method_exists($handler, 'handle')) {
            throw new RuntimeException('Bad handler for job type ' . $type);
        }
        return $handler;
    }
}
