<?php

// autoload_static.php @generated by Composer

namespace Composer\Autoload;

class ComposerStaticInitc77047da30e742db7e75c8c7502a51f5
{
    public static $files = array (
        'a4ecaeafb8cfb009ad0e052c90355e98' => __DIR__ . '/..' . '/beberlei/assert/lib/Assert/functions.php',
    );

    public static $prefixLengthsPsr4 = array (
        'Y' => 
        array (
            'YellowCube\\' => 11,
        ),
        'P' => 
        array (
            'Psr\\Log\\' => 8,
        ),
        'A' => 
        array (
            'Assert\\' => 7,
        ),
    );

    public static $prefixDirsPsr4 = array (
        'YellowCube\\' => 
        array (
            0 => __DIR__ . '/..' . '/swisspost-yellowcube/yellowcube-php/src/YellowCube',
        ),
        'Psr\\Log\\' => 
        array (
            0 => __DIR__ . '/..' . '/psr/log/Psr/Log',
        ),
        'Assert\\' => 
        array (
            0 => __DIR__ . '/..' . '/beberlei/assert/lib/Assert',
        ),
    );

    public static $prefixesPsr0 = array (
        'W' => 
        array (
            'Wse' => 
            array (
                0 => __DIR__ . '/..' . '/course-hero/wse-php/src',
            ),
        ),
    );

    public static function getInitializer(ClassLoader $loader)
    {
        return \Closure::bind(function () use ($loader) {
            $loader->prefixLengthsPsr4 = ComposerStaticInitc77047da30e742db7e75c8c7502a51f5::$prefixLengthsPsr4;
            $loader->prefixDirsPsr4 = ComposerStaticInitc77047da30e742db7e75c8c7502a51f5::$prefixDirsPsr4;
            $loader->prefixesPsr0 = ComposerStaticInitc77047da30e742db7e75c8c7502a51f5::$prefixesPsr0;

        }, null, ClassLoader::class);
    }
}
