<?php

/** Maps a job type to its handler class. A handler has handle(array $payload) and returns a string or JSON-encodable value. */
class Registry
{
    /** Feature cards add one line each: 'type' => HandlerClass::class. */
    public const DEFAULT_MAP = [
    ];

    /** @var array<string, string> */
    private array $map;

    public function __construct(?array $map = null)
    {
        $this->map = $map ?? self::DEFAULT_MAP;
    }

    /** Loads a map from a PHP file that returns an array; the file may require its handler classes. */
    public static function fromFile(string $path): self
    {
        $map = require $path;
        if (!is_array($map)) {
            throw new RuntimeException('Registry file must return an array: ' . $path);
        }
        return new self($map);
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
        $class = $this->map[$type];
        if (!class_exists($class) || !method_exists($class, 'handle')) {
            throw new RuntimeException('Bad handler for job type ' . $type);
        }
        $result = (new $class())->handle($payload);
        if ($result === null) {
            return '';
        }
        return is_string($result) ? $result : json_encode($result, JSON_UNESCAPED_SLASHES);
    }
}
