<?php

// autoload_static.php @generated by Composer

namespace Composer\Autoload;

class ComposerStaticInit5af4d0e3cf543653d0f5b00685447b1f
{
    public static $files = array (
        '3937806105cc8e221b8fa8db5b70d2f2' => __DIR__ . '/..' . '/wp-cli/mustangostang-spyc/includes/functions.php',
        'be01b9b16925dcb22165c40b46681ac6' => __DIR__ . '/..' . '/wp-cli/php-cli-tools/lib/cli/cli.php',
    );

    public static $prefixLengthsPsr4 = array (
        'S' => 
        array (
            'Symfony\\Component\\Finder\\' => 25,
        ),
        'R' => 
        array (
            'RRZE\\CLI\\' => 9,
        ),
        'P' => 
        array (
            'Psr\\Http\\Message\\' => 17,
            'PhpZip\\' => 7,
        ),
        'M' => 
        array (
            'Mustangostang\\' => 14,
        ),
    );

    public static $prefixDirsPsr4 = array (
        'Symfony\\Component\\Finder\\' => 
        array (
            0 => __DIR__ . '/..' . '/symfony/finder',
        ),
        'RRZE\\CLI\\' => 
        array (
            0 => __DIR__ . '/../..' . '/includes',
        ),
        'Psr\\Http\\Message\\' => 
        array (
            0 => __DIR__ . '/..' . '/psr/http-message/src',
        ),
        'PhpZip\\' => 
        array (
            0 => __DIR__ . '/..' . '/nelexa/zip/src',
        ),
        'Mustangostang\\' => 
        array (
            0 => __DIR__ . '/..' . '/wp-cli/mustangostang-spyc/src',
        ),
    );

    public static $prefixesPsr0 = array (
        'c' => 
        array (
            'cli' => 
            array (
                0 => __DIR__ . '/..' . '/wp-cli/php-cli-tools/lib',
            ),
        ),
        'W' => 
        array (
            'WP_CLI\\' => 
            array (
                0 => __DIR__ . '/..' . '/wp-cli/wp-cli/php',
            ),
        ),
        'M' => 
        array (
            'Mustache' => 
            array (
                0 => __DIR__ . '/..' . '/mustache/mustache/src',
            ),
        ),
    );

    public static $classMap = array (
        'Composer\\InstalledVersions' => __DIR__ . '/..' . '/composer/InstalledVersions.php',
        'WP_CLI' => __DIR__ . '/..' . '/wp-cli/wp-cli/php/class-wp-cli.php',
        'WP_CLI_Command' => __DIR__ . '/..' . '/wp-cli/wp-cli/php/class-wp-cli-command.php',
    );

    public static function getInitializer(ClassLoader $loader)
    {
        return \Closure::bind(function () use ($loader) {
            $loader->prefixLengthsPsr4 = ComposerStaticInit5af4d0e3cf543653d0f5b00685447b1f::$prefixLengthsPsr4;
            $loader->prefixDirsPsr4 = ComposerStaticInit5af4d0e3cf543653d0f5b00685447b1f::$prefixDirsPsr4;
            $loader->prefixesPsr0 = ComposerStaticInit5af4d0e3cf543653d0f5b00685447b1f::$prefixesPsr0;
            $loader->classMap = ComposerStaticInit5af4d0e3cf543653d0f5b00685447b1f::$classMap;

        }, null, ClassLoader::class);
    }
}
