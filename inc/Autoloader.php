<?php
/**
 * PSR-4 autoloader for the Authlify namespace.
 *
 * @package Authlify
 */

namespace Authlify;

defined('ABSPATH') || exit;

/**
 * Autoloader.
 */
final class Autoloader
{
    /**
     * Register the autoloader.
     */
    public static function register()
    {
        spl_autoload_register(array(__CLASS__, 'load'));
    }

    /**
     * Load a class file.
     *
     * @param string $class Class name.
     */
    public static function load($class)
    {
        if (0 !== strpos($class, __NAMESPACE__ . '\\')) {
            return;
        }

        $relative = substr($class, strlen(__NAMESPACE__) + 1);
        $file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';

        if (is_readable($file)) {
            require_once $file;
        }
    }
}
