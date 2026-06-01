<?php

if(!interface_exists('Psr\\SimpleCache\\CacheInterface')){
    eval('namespace Psr\\SimpleCache; interface CacheInterface { public function get(string $key, mixed $default = null): mixed; public function set(string $key, mixed $value, null|int|\\DateInterval $ttl = null): bool; public function delete(string $key): bool; public function clear(): bool; public function getMultiple(iterable $keys, mixed $default = null): iterable; public function setMultiple(iterable $values, null|int|\\DateInterval $ttl = null): bool; public function deleteMultiple(iterable $keys): bool; public function has(string $key): bool; }');
}

if(!class_exists('Composer\\Pcre\\Preg')){
    eval('namespace Composer\\Pcre; class Preg { public static function isMatch(string $pattern, string $subject, ?array &$matches = null, int $flags = 0, int $offset = 0): bool { if($matches === null){ $localMatches = []; $result = preg_match($pattern, $subject, $localMatches, $flags, $offset); }else{ $result = preg_match($pattern, $subject, $matches, $flags, $offset); } return $result === 1; } public static function replace(string|array $pattern, string|array $replacement, string|array $subject, int $limit = -1, ?int &$count = null): string|array { return preg_replace($pattern, $replacement, $subject, $limit, $count) ?? (is_array($subject) ? [] : ""); } public static function replaceCallback(string|array $pattern, callable $callback, string|array $subject, int $limit = -1, ?int &$count = null, int $flags = 0): string|array { return preg_replace_callback($pattern, $callback, $subject, $limit, $count, $flags) ?? (is_array($subject) ? [] : ""); } public static function split(string $pattern, string $subject, int $limit = -1, int $flags = 0): array|false { return preg_split($pattern, $subject, $limit, $flags); } }');
}

spl_autoload_register(function($class){
    $prefix = 'PhpOffice\\PhpSpreadsheet\\';
    if(strpos($class, $prefix) !== 0){
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/PhpSpreadsheet-5.7.0/src/PhpSpreadsheet/' . str_replace('\\', '/', $relative) . '.php';

    if(file_exists($file)){
        require_once $file;
    }
});
