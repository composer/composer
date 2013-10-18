<!--
    tagline: Solving problems
-->
# Troubleshooting

This is a list of common pitfalls on using Composer, and how to avoid them.

## General

1. Before asking anyone, run [`composer diagnose`](../03-cli.md#diagnose) to check
   for common problems. If it all checks out, proceed to the next steps.

2. When facing any kind of problems using Composer, be sure to **work with the
   latest version**. See [self-update](../03-cli.md#self-update) for details.

3. Make sure you have no problems with your setup by running the installer's
   checks via `curl -sS https://getcomposer.org/installer | php -- --check`.

4. Ensure you're **installing vendors straight from your `composer.json`** via
   `rm -rf vendor && composer update -v` when troubleshooting, excluding any
   possible interferences with existing vendor installations or `composer.lock`
   entries.

## Package not found

1. Double-check you **don't have typos** in your `composer.json` or repository
   branches and tag names.

2. Be sure to **set the right
   [minimum-stability](../04-schema.md#minimum-stability)**. To get started or be
   sure this is no issue, set `minimum-stability` to "dev".

3. Packages **not coming from [Packagist](https://packagist.org/)** should
   always be **defined in the root package** (the package depending on all
   vendors).

4. Use the **same vendor and package name** throughout all branches and tags of
   your repository, especially when maintaining a third party fork and using
   `replace`.

## Package not found on travis-ci.org

1. Check the ["Package not found"](#package-not-found) item above.

2. If the package tested is a dependency of one of its dependencies (cyclic
   dependency), the problem might be that composer is not able to detect the version
   of the package properly. If it is a git clone it is generally alright and Composer
   will detect the version of the current branch, but travis does shallow clones so
   that process can fail when testing pull requests and feature branches in general.
   The best solution is to define the version you are on via an environment variable
   called COMPOSER_ROOT_VERSION. You set it to `dev-master` for example to define
   the root package's version as `dev-master`.
   Use: `before_script: COMPOSER_ROOT_VERSION=dev-master composer install` to export
   the variable for the call to composer.

## Need to override a package version

Let say your project depends on package A which in turn depends on a specific
version of package B (say 0.1) and you need a different version of that
package - version 0.11.

You can fix this by aliasing version 0.11 to 0.1:

composer.json:

    {
        "require": {
            "A": "0.2",
            "B": "0.11 as 0.1"
        }
    }

See [aliases](aliases.md) for more information.

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

## "The system cannot find the path specified" (Windows)

1. Open regedit.
2. Search for an ```AutoRun``` key inside ```HKEY_LOCAL_MACHINE\Software\Microsoft\Command Processor```
   or ```HKEY_CURRENT_USER\Software\Microsoft\Command Processor```.
3. Check if it contains any path to non-existent file, if it's the case, just remove them.
