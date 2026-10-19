<?php
require_once __DIR__ . '/../Fs/SafeDir.php';

// The Obsidian vault Rob opens. Writes only `<Folder>/<name>.<ext>` paths that code built, inside a fixed set of
// folders, each written atomically (a temp file in the same folder, then a rename). The root and every folder must
// pass SafeDir, so a junction cannot send a write anywhere else.
class Vault
{
    public const FOLDERS = ['Projects', 'Briefings', 'Memory', 'Boards', 'Maps'];
    // Windows reserved device names are refused with any extension: `nul.md` opens the device, not a file.
    private const NAME = '/^(Projects|Briefings|Memory|Boards|Maps)\/(?!(?:con|nul|aux|prn|com[1-9]|lpt[1-9])\.)([a-z0-9-]+\.(?:md|canvas))$/D';

    private string $root;

    // The root is created if missing, then must be a plain folder: a junction or link root is refused.
    public function __construct(string $root)
    {
        $root = rtrim(str_replace('/', '\\', $root), '\\');
        if (!preg_match('/^[A-Za-z]:\\\\[^\0]+$/', $root) || preg_match('/(^|\\\\)\.\.?(\\\\|$)/', $root)) {
            throw new InvalidArgumentException('vault root must be an absolute folder path');
        }
        clearstatcache(true);
        if (@lstat($root) === false) {
            @mkdir($root, 0777, true);
        }
        if (!SafeDir::isDirectChild(dirname($root), basename($root))) {
            throw new RuntimeException('vault root is not a plain folder');
        }
        $this->root = $root;
    }

    public static function fromConfig(): self
    {
        $c = require __DIR__ . '/../../config/vault.php';
        return new self($c['root']);
    }

    public function root(): string
    {
        return $this->root;
    }

    // Writes $content to $rel unless the file already holds exactly that. True when it wrote.
    public function write(string $rel, string $content): bool
    {
        $path = $this->target($rel);
        if ($this->currentBytes($path) === $content) {
            return false;
        }
        $this->atomic($path, $content);
        return true;
    }

    // Writes $content only when $rel does not exist yet. An existing file is never touched.
    public function createOnce(string $rel, string $content): bool
    {
        $path = $this->target($rel);
        clearstatcache(true);
        if (@lstat($path) !== false) {
            return false;
        }
        $this->atomic($path, $content);
        return true;
    }

    // Untrusted text (git, a model, the loop's note) as one line that Obsidian shows as plain text: no wikilink,
    // embed, markdown link or image, HTML, autolink, URI scheme, UNC path, tag, comment, math, Dataview field or
    // block syntax survives as live syntax, wherever on a line the result is placed. Each syntax character is swapped
    // for a lookalike that no parser reads, so this holds whatever Obsidian's parser does with backslash escapes.
    public static function neutralise(string $text): string
    {
        $text = mb_scrub($text, 'UTF-8');
        // Newlines would let the text start a heading, a list or a second front matter; format characters (bidi
        // controls, zero-widths, tag characters) hide or reorder what is shown.
        $text = preg_replace('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}\x{E0000}-\x{E007F}]/u', ' ', $text);
        // `$` is MathJax, whose \href and \style run links and CSS; a backslash would let CSS escapes rebuild a URL.
        $text = strtr($text, [
            '[' => "\u{FF3B}", ']' => "\u{FF3D}", '<' => "\u{2039}", '>' => "\u{203A}",
            '#' => "\u{FF03}", '%' => "\u{FF05}", '`' => "\u{2018}", '|' => "\u{FF5C}",
            '$' => "\u{FF04}", '{' => "\u{FF5B}", '}' => "\u{FF5D}", '~' => "\u{FF5E}", '\\' => "\u{29F5}",
            '::' => "\u{A789}\u{A789}",
        ]);
        // `https:x`, `obsidian://`, `file:`, `javascript: x` and every other scheme, slashes or not, space or not.
        $text = preg_replace('/([A-Za-z][A-Za-z0-9+.\-]*):/', "\$1\u{A789}", $text);
        $text = preg_replace('/www\./i', "www\u{2024}", $text);
        // Protocol-relative `//host`.
        $text = preg_replace_callback('/\/{2,}/', fn($m) => str_repeat("\u{2044}", strlen($m[0])), $text);
        $text = trim($text);
        // At a line start these would open a list, a rule, a setext heading or an ordered list.
        $text = preg_replace_callback('/^(?:[-+*=_]|\d+[.)])/', fn($m) => strtr($m[0], [
            '-' => "\u{2010}", '+' => "\u{FF0B}", '*' => "\u{2217}", '=' => "\u{FF1D}", '_' => "\u{FF3F}",
            '.' => "\u{2024}", ')' => "\u{FF09}",
        ]), $text);
        return $text;
    }

    private function target(string $rel): string
    {
        if (!preg_match(self::NAME, $rel, $m)) {
            throw new InvalidArgumentException('not a vault path: ' . $rel);
        }
        $dir = $this->root . '\\' . $m[1];
        clearstatcache(true);
        if (@lstat($dir) === false) {
            @mkdir($dir);
        }
        if (!SafeDir::isDirectChild($this->root, $m[1])) {
            throw new RuntimeException('vault folder is not a plain folder: ' . $m[1]);
        }
        $path = $dir . '\\' . $m[2];
        $lst = @lstat($path);
        if ($lst !== false && ($lst['mode'] & 0170000) !== 0100000) {
            throw new RuntimeException('vault file is not a regular file: ' . $rel);
        }
        return $path;
    }

    private function currentBytes(string $path): ?string
    {
        clearstatcache(true);
        if (@lstat($path) === false) {
            return null;
        }
        $bytes = @file_get_contents($path);
        return $bytes === false ? null : $bytes;
    }

    // Dot-named so Obsidian ignores it while it exists; 'x' mode never opens a file that is already there.
    private function atomic(string $path, string $content): void
    {
        $tmp = dirname($path) . '\\.' . basename($path) . '.' . bin2hex(random_bytes(6)) . '.tmp';
        $fh = @fopen($tmp, 'xb');
        if ($fh === false) {
            throw new RuntimeException('could not create a temp file in the vault');
        }
        $ok = fwrite($fh, $content) === strlen($content) && fflush($fh);
        fclose($fh);
        if (!$ok || !@rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('vault write failed: ' . basename($path));
        }
    }
}
