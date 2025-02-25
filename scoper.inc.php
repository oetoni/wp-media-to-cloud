<?php

declare(strict_types=1);

use Isolated\Symfony\Component\Finder\Finder;

return [
    /*
     * The prefix you want to apply. This will be prepended to classes/functions/constants
     * in the libraries you are scoping. For instance, if your prefix is 'DOSpacesVendor',
     * then 'Aws\S3\S3Client' might become 'DOSpacesVendor\Aws\S3\S3Client'.
     */
    'prefix' => 'DOSpacesVendor',

    /*
     * Which files/folders to find and scope.
     * Typically you point to 'vendor/aws/aws-sdk-php' and possibly other libraries you want
     * to isolate. If you want to scope *all* vendor dependencies, you can specify 'vendor'.
     *
     * You can add multiple Finder instances for more complex setups.
     */
    'finders' => [
        Finder::create()
            ->files()
            ->in(__DIR__ . '/vendor/aws/aws-sdk-php')
            ->name('*.php'),

        // If you need Action Scheduler or other dependencies also isolated,
        // you can add them here as well. For example:
        // Finder::create()
        //     ->files()
        //     ->in(__DIR__ . '/vendor/woocommerce/action-scheduler')
        //     ->name('*.php'),
    ],

    /*
     * Whitelisting (AKA "exclude" or "do not rename") certain classes/functions/constants.
     * For instance, if you want to ensure WordPress global functions or certain symbols
     * remain untouched, you can list them here.
     *
     * By default, WordPress itself is not inside your vendor/ folder, so you generally don't
     * need to whitelist WP symbols. But if you find any collisions or references that must
     * remain unprefixed, add them here.
     */
    'whitelist' => [
        // 'WP_*', // example: if you had WP constants you do not want scoped
        // 'some_global_function',
    ],

    /*
     * Whitelist patterns using Regex. For example, if you want to exclude
     * everything that starts with "WordPress".
     */
    'whitelist-global-classes' => false,
    'whitelist-global-constants' => false,
    'whitelist-global-functions' => false,

    /*
     * Sometimes you need "patchers" to fix code references after scoping.
     * For instance, if the library uses string references to the original namespace.
     * This is a more advanced topic; see the PHP-Scoper docs for examples.
     */
    'patchers' => [
        // Example:
        // static function (string $filePath, string $prefix, string $content) {
        //     // If there's a known string reference to 'Aws\' that won't be automatically replaced,
        //     // you could handle it here with str_replace or a regex.
        //     return $content;
        // },
    ],

    /*
     * If you have additional configuration like constants, namespaces, or
     * specific classes to exclude, you can define them here.
     */
    'exclude-namespaces' => [
        // For instance, if you had your own plugin namespace that you do NOT want to scope:
        // 'MyPlugin\\',
    ],
    'exclude-classes' => [],
    'exclude-functions' => [],
    'exclude-constants' => [],

    /*
     * If you’d like to expose certain classes back to the global namespace after scoping,
     * you can list them here. This is rarely needed, but can be useful for debugging or
     * if some other code references them by FQCN.
     */
    'expose-classes' => [
        // e.g., 'DOSpacesVendor\\Aws\\Exception\\AwsException'
    ],
];
