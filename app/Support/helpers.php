<?php

if (!function_exists('config_path')) {
    function config_path(string $path = ''): string
    {
        return app()->basePath('config' . ($path ? '/' . $path : $path));
    }
}

if (!function_exists('public_path')) {
    function public_path(string $path = ''): string
    {
        return app()->basePath('public' . ($path ? '/' . $path : $path));
    }
}

if (!function_exists('storage_path')) {
    function storage_path(string $path = ''): string
    {
        return app()->basePath('storage' . ($path ? '/' . $path : $path));
    }
}

if (!function_exists('resource_path')) {
    function resource_path(string $path = ''): string
    {
        return app()->basePath('resources' . ($path ? '/' . $path : $path));
    }
}