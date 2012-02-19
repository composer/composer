# Basic usage

## Installation

To install composer, simply run this command on the command line:

    $ curl -s http://getcomposer.org/installer | php

This will perform some checks on your environment to make sure you can
actually run it.

This will download `composer.phar` and place it in your working directory.
`composer.phar` is the composer binary. It is a PHAR (PHP archive), which
is an archive format for PHP which can be run on the command line, amongst
other things.

You can place this file anywhere you wish. If you put it in your `PATH`,
you can access it globally. On unixy systems you can even make it
executable and invoke it without `php`.

To check if composer is working, just run the PHAR through `php`:

    $ php composer.phar

This should give you a list of available commands.

> **Note:** You can also perform the checks only without downloading composer by using the `--check` option. For more information, just use `--help`.

    $ curl -s http://getcomposer.org/installer | php -- --help

## Project setup

To start using composer in your project, all you need is a `composer.json` file. This file describes the dependencies of your project and may contain
other metadata as well.

The [JSON format](http://json.org/) is quite easy to write. It allows you to
define nested structures.

The first (and often only) thing you specify in `composer.json` is the
`require` key. You're simply telling composer which packages your project
depends on.

```json
{
    "require": {
        "monolog/monolog": "1.0.*"
    }
}
```

As you can see, `require` takes an object that maps package names to versions.

## Package names

The package name consists of a vendor name and the project's name. Often these
will be identical. The vendor name exists to prevent naming clashes. It allows
two different people to create a library named `json`, which would then just be
named `igorw/json` and `seldaek/json`.

Here we are requiring `monolog/monolog`, so the vendor name is the same as the
project's name. For projects with a unique name this is recommended. It also
allows adding more related projects under the same namespace later on. If you
are maintaining a library, this would make it really easy to split it up into
smaller decoupled parts.

## Package versions

We are also requiring the version `1.0.*` of monolog. This means any version
in the `1.0` development branch. It would match `1.0.0`, `1.0.2` and `1.0.20`.

Version constraints can be specified in a few different ways.

* **Exact version:** You can specify the exact version of a package, for
  example `1.0.2`. This is not used very often, but can be useful.

* **Range:** By using comparison operators you can specify ranges of valid
  versions. Valid operators are `>`, `>=`, `<`, `<=`. An example range would be `>=1.0`. You can define multiple of these, separated by comma:
  `>=1.0,<2.0`.

* **Wildcard:** You can specify a pattern with a `*` wildcard. `1.0.*` is the equivalent of `>=1.0,<1.1-dev`.

## Installing dependencies

To fetch the defined dependencies into the local project, you simply run the
`install` command of `composer.phar`.

    $ php composer.phar install

This will find the latest version of `monolog/monolog` that matches the
supplied version constraint and download it into the the `vendor` directory.
It's a convention to put third party code into a directory named `vendor`.
In case of monolog it will put it into `vendor/monolog/monolog`.

**Tip:** If you are using git for your project, you probably want to add
`vendor` into your `.gitignore`. You really don't want to add all of that
code to your repository.

Another thing that the `install` command does is it adds a `composer.lock` file
into your project root.

## Lock file

After installing the dependencies, composer writes the list of the exact
versions it installed into a `composer.lock` file. This locks the project
to those specific versions.

**Commit your project's `composer.lock` into version control.**

The reason is that anyone who sets up the project should get the same version.
The `install` command will check if a lock file is present. If it is, it will
use the versions specified there. If not, it will resolve the dependencies and
create a lock file.

If any of the dependencies gets a new version, you can update to that version
by using the `update` command. This will fetch the latest matching versions and
also update the lock file.

    $ php composer.phar update

## Packagist

[Packagist](http://packagist.org/) is the main composer repository. A composer repository is basically a package source. A place where you can get packages from. Packagist aims to be the central repository that everybody uses. This means that you can automatically `require` any package that is available there.

If you go to the [packagist website](http://packagist.org/) (packagist.org), you can browse and search for packages.

Any open source project using composer should publish their packages on packagist.

## Autoloading

For libraries that follow the [PSR-0](https://github.com/php-fig/fig-standards/blob/master/accepted/PSR-0.md) naming standard, composer generates
a `vendor/.composer/autoload.php` file for autoloading. You can simply include this file and you will get autoloading for free.

```php
require 'vendor/.composer/autoload.php';
```

This makes it really easy to use third party code, because you really just have to add one line to `composer.json` and run `install`. For monolog, it means that we can just start using classes from it, and they will be autoloaded.

```php
$log = new Monolog\Logger('name');
$log->pushHandler(new Monolog\Handler\StreamHandler('app.log', Logger::WARNING));

$log->addWarning('Foo');
```

You can even add your own code to the autoloader by adding an `autoload` key to `composer.json`.

```json
{
    "autoload": {
        "psr-0": {"Acme": "src/"}
    }
}
```

This is a mapping from namespaces to directories. The `src` directory would be in your project root. An example filename would be `src/Acme/Foo.php` containing a `Acme\Foo` class.

After adding the `autoload` key, you have to re-run `install` to re-generate the `vendor/.composer/autoload.php` file.

Including that file will also return the autoloader instance, so you can add retrieve it and add more namespaces. This can be useful for autoloading classes in a test suite, for example.

```php
$loader = require 'vendor/.composer/autoload.php';
$loader->add('Acme\Test', __DIR__);
```

> **Note:** Composer provides its own autoloader. If you don't want to use that one, you can just include `vendor/.composer/autoload_namespaces.php`, which returns an associative array mapping namespaces to directories.
