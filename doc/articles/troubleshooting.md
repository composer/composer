<!--
    tagline: Solving problems
-->
# Troubleshooting

This is a list of common pitfalls on using Composer, and how to avoid them.

## General

1. When facing any kind of problems using Composer, be sure to **work with the
   latest version**. See [self-update](../03-cli.md#self-update) for details.

2. Ensure you're **installing vendors straight from your `composer.json`** via
   `rm -rf vendor && composer update -v` when troubleshooting, excluding any
   possible interferences with existing vendor installations or `composer.lock`
   entries.

## Package not found

1. Double-check you **don't have typos** in your `composer.json` or repository
   branches and tag names.

2. Be sure to **set the right
   [minimum-stability](../04-schema.md#minimum-stability)**. To get started or be
   sure this is no issue, set `minimum-stability` to "dev".

3. Packages **not coming from [Packagist](http://packagist.org/)** should
   always be **defined in the root package** (the package depending on all
   vendors).

4. Use the **same vendor and package name** throughout all branches and tags of
   your repository, especially when maintaining a third party fork and using
   `replace`.

## Memory limit errors

If composer shows memory errors on some commands:

    PHP Fatal error:  Allowed memory size of XXXXXX bytes exhausted <...>

The PHP `memory_limit` should be increased.

> **Note:** Composer internally increases the `memory_limit` to `512M`.
> If you have memory issues when using composer, please consider [creating
> an issue ticket](https://github.com/composer/composer/issues) so we can look into it.

To get the current `memory_limit` value, run:

    php -r "echo ini_get('memory_limit').PHP_EOL;"

Try increasing the limit in your `php.ini` file (ex. `/etc/php5/cli/php.ini` for
Debian-like systems):

    ; Use -1 for unlimited or define an explicit value like 512M
    memory_limit = -1

Or, you can increase the limit with a command-line argument:

    php -d memory_limit=-1 composer.phar <...>

