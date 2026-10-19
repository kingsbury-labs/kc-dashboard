<?php
require_once __DIR__ . '/../Fs/SafeDir.php';
require_once __DIR__ . '/../Log.php';

// The folders Ask Claude may run in. Listed fresh on every call; a name resolves only if it is in the listing.
// An entry is ['name' => basename, 'parent' => folder it sits in, 'busy_if_dirty' => ?string].
class ProjectList
{
    public const NAME = '/^[A-Za-z0-9._-]+$/';
    private const ABSOLUTE = '/^[A-Za-z]:\\\\[^:*?"<>|\/\0]+$/';

    private string $root;
    private string $home;
    /** @var array<int,string|array{path:string,busy_if_dirty?:string}> */
    private array $extra;
    private ?Log $log;

    public function __construct(string $root, array $extra = [], string $home = '', ?Log $log = null)
    {
        $this->root = $root;
        $this->extra = $extra;
        $this->home = $home;
        $this->log = $log;
    }

    public static function fromConfig(?Log $log = null): self
    {
        $c = require __DIR__ . '/../../config/projects.php';
        return new self($c['root'], $c['extra'] ?? [], $c['home'] ?? '', $log);
    }

    /** @return string[] */
    public function names(): array
    {
        return array_keys($this->entries());
    }

    public function resolve(string $name): ?array
    {
        if (!preg_match(self::NAME, $name)) {
            return null;
        }
        return $this->entries()[$name] ?? null;
    }

    // The home folder as an entry, checked the same way; null when it is not a plain folder.
    public function home(): ?array
    {
        return $this->entry($this->home, null);
    }

    /** @return array<string,array{name:string,parent:string,busy_if_dirty:?string}> */
    private function entries(): array
    {
        $out = [];
        foreach (@scandir($this->root) ?: [] as $name) {
            if (preg_match(self::NAME, $name) && SafeDir::isDirectChild($this->root, $name)) {
                $out[$name] = ['name' => $name, 'parent' => $this->root, 'busy_if_dirty' => null];
            }
        }
        foreach ($this->extra as $item) {
            $path = is_array($item) ? (string) ($item['path'] ?? '') : (string) $item;
            $busy = is_array($item) && isset($item['busy_if_dirty']) ? (string) $item['busy_if_dirty'] : null;
            $entry = $this->entry($path, $busy);
            if ($entry === null) {
                continue;
            }
            foreach (array_keys($out) as $have) {
                // Folder names are case-insensitive on Windows, so Alpha and alpha are one name.
                if (strcasecmp($have, $entry['name']) === 0) {
                    $this->log?->info('project extra skipped: name collides with a root folder', ['path' => $path]);
                    continue 2;
                }
            }
            $out[$entry['name']] = $entry;
        }
        return $out;
    }

    private function entry(string $path, ?string $busy): ?array
    {
        if (!preg_match(self::ABSOLUTE, $path)) {
            return null;
        }
        $path = rtrim($path, '\\');
        $name = basename($path);
        $parent = dirname($path);
        if (!preg_match(self::NAME, $name) || !SafeDir::isDirectChild($parent, $name)) {
            return null;
        }
        return ['name' => $name, 'parent' => $parent, 'busy_if_dirty' => $busy];
    }
}
