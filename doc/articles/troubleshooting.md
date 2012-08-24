<!--
    tagline: Solving problems
-->
# Memory limit errors

If composer shows memory errors on some commands:

    PHP Fatal error:  Allowed memory size of XXXXXX bytes exhausted <...>

The `memory_limit` ini value should be increased.

> **Note:** Composer internaly increases the memory_limit to 256M.
> It is a good idea to create an issue for composer if you get memory errors.

Get current value:

    php -r "echo ini_get('memory_limit').PHP_EOL;"


Increase limit with `php.ini` for a `CLI SAPI` (ex. `/etc/php5/cli/php.ini` for Debian-like systems):

    ; Use -1 for unlimited or define explicit value like 512M
    memory_limit = -1

Or with command line arguments:

    php -d memory_limit=-1 composer.phar <...>

