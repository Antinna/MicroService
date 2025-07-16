<?php

function config(string $key, $default = null): mixed
{
    static $configs = [];

    [$file, $path] = explode('.', $key, 2);

    if (!isset($configs[$file])) {
        $configs[$file] = require dirname(__DIR__) . "/config/{$file}.php";
    }

    $value = $configs[$file];
    foreach (explode('.', $path) as $segment) {
        if (!is_array($value) || !array_key_exists($segment, $value)) {
            return $default;
        }
        $value = $value[$segment];
    }

    return $value;
}
