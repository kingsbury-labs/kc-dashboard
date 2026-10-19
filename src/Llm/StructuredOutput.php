<?php

// Checks a model's JSON reply against a field schema. Unknown fields are dropped, strings over their cap are
// truncated, and a missing required field, a wrong type, an out-of-enum value or bad JSON gives null.
// Schema: field => ['type' => 'string'|'int'|'bool', 'required' => bool, 'enum' => [...], 'max' => int]
class StructuredOutput
{
    public static function parse(string $json, array $schema): ?array
    {
        $json = trim($json);
        // Models often fence JSON even when told not to.
        if (preg_match('/^```(?:json)?\s*\n(.*)\n```$/s', $json, $m)) {
            $json = trim($m[1]);
        }
        $data = json_decode($json, true);
        if (!is_array($data) || array_is_list($data) && $data !== []) {
            return null;
        }
        return self::validate($data, $schema);
    }

    public static function validate(array $data, array $schema): ?array
    {
        $out = [];
        foreach ($schema as $field => $rule) {
            if (!array_key_exists($field, $data)) {
                if ($rule['required'] ?? false) {
                    return null;
                }
                continue;
            }
            $value = $data[$field];
            $type = $rule['type'] ?? 'string';
            if (($type === 'string' && !is_string($value)) || ($type === 'int' && !is_int($value))
                || ($type === 'bool' && !is_bool($value))) {
                return null;
            }
            if (isset($rule['enum']) && !in_array($value, $rule['enum'], true)) {
                return null;
            }
            if ($type === 'string' && isset($rule['max']) && mb_strlen($value, 'UTF-8') > $rule['max']) {
                $value = mb_substr($value, 0, $rule['max'], 'UTF-8');
            }
            $out[$field] = $value;
        }
        return $out;
    }
}
