<?php

// Scans the page's JavaScript for APIs that turn a string into markup or code. Server data must go through
// textContent, so any of these in public/assets is a finding, comments included.
class DomSinks
{
    public const SINKS = ['innerHTML', 'outerHTML', 'insertAdjacentHTML', 'document.write', 'srcdoc', 'eval(', 'new Function',
        'setHTMLUnsafe', 'createContextualFragment', 'DOMParser'];

    // name => pattern. Function( catches the constructor called bare or as window.Function. A timer's first
    // argument must be a function written in place, `(...) =>`, `async` or `function`: anything else, a string
    // or a name that could hold one, is a hit.
    public const PATTERNS = [
        'Function(' => '/(?<![\w$])Function\s*\(/',
        'timer string' => '/\bset(?:Timeout|Interval)\s*\(\s*(?!\(|async\b|function\b)/',
    ];

    /** @return string[] "file: sink" for every hit */
    public static function find(string $dir): array
    {
        $hits = [];
        foreach (glob(rtrim($dir, '\\/') . '/*.js') ?: [] as $file) {
            $src = (string) file_get_contents($file);
            foreach (self::SINKS as $sink) {
                if (str_contains($src, $sink)) {
                    $hits[] = basename($file) . ': ' . $sink;
                }
            }
            foreach (self::PATTERNS as $name => $re) {
                if (preg_match($re, $src)) {
                    $hits[] = basename($file) . ': ' . $name;
                }
            }
        }
        return $hits;
    }
}
