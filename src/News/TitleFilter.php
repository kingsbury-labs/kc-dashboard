<?php

// Titles that are not stories: a bare repo slug (owner/name), a bare name or a name with a version string
// ("datasette-llm 0.1a9"), and link-blog quotes ("Quoting ...").
class TitleFilter
{
    public static function keep(string $title): bool
    {
        $t = trim($title);
        if ($t === '' || stripos($t, 'Quoting ') === 0) {
            return false;
        }
        if (preg_match('~^[\w.-]+/[\w.-]+$~u', $t) === 1) {
            return false;
        }
        $words = preg_split('/\s+/u', $t) ?: [];
        if (count($words) === 1) {
            return false;
        }
        return !(count($words) <= 2 && preg_match('~^v?\d+(\.\d+)+[\w.+-]*$~i', end($words)) === 1);
    }
}
