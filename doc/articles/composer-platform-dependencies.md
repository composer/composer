<!--
    tagline: Making your package depend on specific Composer versions
-->

# Composer platform dependencies

## What are platform dependencies

Composer makes information about the environment Composer runs in available as virtual packages. This allows other
packages to define dependencies ([require](../04-schema.md#require), [conflict](../04-schema.md#conflict),
[provide](../04-schema.md#provide), [replace](../04-schema.md#replace)) on different aspects of the platform, like PHP,
extensions or system libraries, including version constraints.

When you require one of the platform packages no code is installed. The version numbers of platform packages are
derived from the environment Composer is executed in and they cannot be updated or removed. They can however be
overwritten for the purposes of dependency resolution with a [platform configuration](../06-config.md#platform).

**For example:** If you are executing `composer update` with a PHP interpreter in version
`7.4.42`, then Composer automatically adds a package to the pool of available packages
called `php` and assigns version `7.4.42` to it.

That's how packages can add a dependency on the used PHP version:

```json
{
    "require" : {
        "php" : ">=7.4"
    }
}
```

Composer will check this requirement against the currently used PHP version when running the composer command.

### Different types of platform packages

The following types of platform packages exist and can be depended on:

1. PHP (`php` and the subtypes: `php-64bit`, `php-ipv6`, `php-zts` `php-debug`)
2. PHP Extensions (`ext-*`, e.g. `ext-mbstring`)
3. PHP Libraries (`lib-*`, e.g. `lib-curl`)
4. Composer (`composer`, `composer-plugin-api`, `composer-runtime-api`)

To see the complete list of platform packages available in your environment
you can run `php composer.phar show --platform` (or `show -p` for short).

The differences between the various Composer platform packages are explained further in this document.

## Plugin package `composer-plugin-api`

You can modify Composer's behavior with [plugin](plugins.md) packages. Composer provides a set of versioned APIs for
plugins. Because internal Composer changes may **not** change the plugin APIs, the API version may not increase every
time the Composer version increases. E.g. In Composer version `2.3.12`, the `composer-plugin-api` version could still
be `2.2.0`.

## Runtime package `composer-runtime-api`

When applications which were installed with Composer are run (either on CLI or through a web request), they require the
`vendor/autoload.php` file, typically as one of the first lines of executed code. Invocations of the Composer
autoloader are considered the application "runtime".

Starting with version 2.0, Composer makes [additional features](../07-runtime.md) (besides registering the class autoloader) available to the application runtime environment.

Similar to `composer-plugin-api`, not every Composer release adds new runtime features,
thus the version of `composer-runtimeapi` is also increased independently from Composer's version. 

## Composer package `composer`

Starting with Composer 2.2.0, a new platform package called `composer` is available, which represents the exact
Composer version that is executed. Packages depending on this platform package can therefore depend on (or conflict
with) individual Composer versions to cover edge cases where neither the `composer-runtime-api` version nor the
`composer-plugin-api` was changed.

Because this option was introduced with Composer 2.2.0, it is recommended to add a `composer-plugin-api` dependency on
at least `>=2.2.0` to provide a more meaningful error message for users running older Composer versions.

In general, depending on `composer-plugin-api` or `composer-runtime-api` is always recommended
over depending on concrete Composer versions with the `composer` platform package.
