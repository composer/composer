# Runtime Composer utilities

While Composer is mostly used around your project to install its dependencies,
there are a few things which are made available to you at runtime.

If you need to rely on some of these in a specific version, you can require
the `composer-runtime-api` package.

## Autoload

The autoloader is the most used one, and is already covered in our
[basic usage guide](01-basic-usage.md#autoloading). It is available in all
Composer versions.

## Installed versions

composer-runtime-api 2.0 introduced a new `Composer\InstalledVersions` class which offers
a few static methods to inspect which versions are currently installed. This is
automatically available to your code as long as you include the Composer autoloader.

The main use cases for this class are the following:

### Knowing whether package X (or virtual package) is present

```php
\Composer\InstalledVersions::isInstalled('vendor/package'); // returns bool
\Composer\InstalledVersions::isInstalled('psr/log-implementation'); // returns bool
```

Note that this can not be used to check whether platform packages are installed.

### Knowing whether package X is installed in version Y

> **Note:** To use this, your package must require `"composer/semver": "^2.0"`.

```php
use Composer\Semver\VersionParser;

\Composer\InstalledVersions::satisfies(new VersionParser, 'vendor/package', '2.0.*');
\Composer\InstalledVersions::satisfies(new VersionParser, 'psr/log-implementation', '^1.0');
```

This will return true if e.g. vendor/package is installed in a version matching
`2.0.*`, but also if the given package name is replaced or provided by some other
package.

### Knowing the version of package X

> **Note:** This will return `null` if the package name you ask for is not itself installed
> but merely provided or replaced by another package. We therefore recommend using satisfies()
> in library code at least. In application code you have a bit more control and it is less
> important.

```php
// returns a normalized version (e.g. 1.2.3.0) if vendor/package is installed,
// or null if it is provided/replaced,
// or throws OutOfBoundsException if the package is not installed at all
\Composer\InstalledVersions::getVersion('vendor/package');
```

```php
// returns the original version (e.g. v1.2.3) if vendor/package is installed,
// or null if it is provided/replaced,
// or throws OutOfBoundsException if the package is not installed at all
\Composer\InstalledVersions::getPrettyVersion('vendor/package');
```

```php
// returns the package dist or source reference (e.g. a git commit hash) if vendor/package is installed,
// or null if it is provided/replaced,
// or throws OutOfBoundsException if the package is not installed at all
\Composer\InstalledVersions::getReference('vendor/package');
```

### Knowing a package's own installed version

If you are only interested in getting a package's own version, e.g. in the source of acme/foo you want
to know which version acme/foo is currently running to display that to the user, then it is
acceptable to use getVersion/getPrettyVersion/getReference.

The warning in the section above does not apply in this case as you are sure the package is present
and not being replaced if your code is running.

It is nonetheless a good idea to make sure you handle the `null` return value as gracefully as
possible for safety.

----

A few other methods are available for more complex usages, please refer to the
source/docblocks of [the class itself](https://github.com/composer/composer/blob/master/src/Composer/InstalledVersions.php).

## Platform check

composer-runtime-api 2.0 introduced a new `vendor/composer/platform_check.php` file, which
is included automatically when you include the Composer autoloader.

It verifies that platform requirements (i.e. php and php extensions) are fulfilled
by the PHP process currently running. If the requirements are not met, the script
prints a warning with the missing requirements and exits with code 104.

To avoid an unexpected white page of death with some obscure PHP extension warning in
production, you can run `composer check-platform-reqs` as part of your
deployment/build and if that returns a non-0 code you should abort.

The default value is `php-only` which only checks the PHP version.

If you for some reason do not want to use this safety check, and would rather
risk runtime errors when your code executes, you can disable this by setting the
[`platform-check`](06-config.md#platform-check) config option to `false`.

If you want the check to include verifying the presence of PHP extensions,
set the config option to `true`.

&larr; [Config](06-config.md)  |  [Community](08-community.md) &rarr;
