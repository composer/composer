# Basic usage

## Introduction

For our basic usage introduction, we will be installing `monolog/monolog`,
a logging library. If you have not yet installed Composer, refer to the
[Intro](00-intro.md) chapter.

> **Note:** for the sake of simplicity, this introduction will assume you
> have performed a [local](00-intro.md#locally) install of Composer.

## `composer.json`: Project Setup

To start using Composer in your project, all you need is a `composer.json`
file. This file describes the dependencies of your project and may contain
other metadata as well.

### The `require` Key

The first (and often only) thing you specify in `composer.json` is the
[`require`](04-schema.md#require) key. You're simply telling Composer which
packages your project depends on.

```json
{
    "require": {
        "monolog/monolog": "1.0.*"
    }
}
```

As you can see, [`require`](04-schema.md#require) takes an object that maps
**package names** (e.g. `monolog/monolog`) to **version constraints** (e.g.
`1.0.*`).

### Package Names

The package name consists of a vendor name and the project's name. Often these
will be identical - the vendor name just exists to prevent naming clashes. It
allows two different people to create a library named `json`, which would then
just be named `igorw/json` and `seldaek/json`.

Here we are requiring `monolog/monolog`, so the vendor name is the same as the
project's name. For projects with a unique name this is recommended. It also
allows adding more related projects under the same namespace later on. If you
are maintaining a library, this would make it really easy to split it up into
smaller decoupled parts.

### Package Versions

In the previous example we were requiring version
[`1.0.*`](http://semver.mwl.be/#?package=monolog%2Fmonolog&version=1.0.*) of
Monolog. This means any version in the `1.0` development branch. It is the
equivalent of saying versions that match `>=1.0 <1.1`.

Version constraints can be specified in several ways, read
[versions](articles/versions.md) for more in-depth information on this topic.

### Stability

By default only stable releases are taken into consideration. If you would
like to also get RC, beta, alpha or dev versions of your dependencies you can
do so using [stability flags](04-schema.md#package-links). To change that for
all packages instead of doing per dependency you can also use the
[minimum-stability](04-schema.md#minimum-stability) setting.

## Installing Dependencies

To install the defined dependencies for your project, just run the
[`install`](03-cli.md#install) command.

```sh
php composer.phar install
```

This will find the latest version of `monolog/monolog` that matches the
supplied version constraint and download it into the `vendor` directory.
It's a convention to put third party code into a directory named `vendor`.
In case of Monolog it will put it into `vendor/monolog/monolog`.

> **Tip:** If you are using git for your project, you probably want to add
> `vendor` in your `.gitignore`. You really don't want to add all of that
> code to your repository.

You will notice the [`install`](03-cli.md#install) command also created a
`composer.lock` file.

## `composer.lock` - The Lock File

After installing the dependencies, Composer writes the list of the exact
versions it installed into a `composer.lock` file. This locks the project
to those specific versions.

**Commit your application's `composer.lock` (along with `composer.json`)
into version control.**

This is important because the [`install`](03-cli.md#install) command checks
if a lock file is present, and if it is, it downloads the versions specified
there (regardless of what `composer.json` says).

This means that anyone who sets up the project will download the exact same
version of the dependencies. Your CI server, production machines, other
developers in your team, everything and everyone runs on the same dependencies,
which mitigates the potential for bugs affecting only some parts of the
deployments. Even if you develop alone, in six months when reinstalling the
project you can feel confident the dependencies installed are still working even
if your dependencies released many new versions since then.

If no `composer.lock` file exists, Composer will read the dependencies and
versions from `composer.json` and  create the lock file after executing the
[`update`](03-cli.md#update) or the [`install`](03-cli.md#install) command.

This means that if any of the dependencies get a new version, you won't get the
updates automatically. To update to the new version, use the
[`update`](03-cli.md#update) command. This will fetch the latest matching
versions (according to your `composer.json` file) and also update the lock file
with the new version.

```sh
php composer.phar update
```
> **Note:** Composer will display a Warning when executing an `install` command
> if `composer.lock` and `composer.json` are not synchronized.

If you only want to install or update one dependency, you can whitelist them:

```sh
php composer.phar update monolog/monolog [...]
```

> **Note:** For libraries it is not necessary to commit the lock
> file, see also: [Libraries - Lock file](02-libraries.md#lock-file).

## Packagist

[Packagist](https://packagist.org/) is the main Composer repository. A Composer
repository is basically a package source: a place where you can get packages
from. Packagist aims to be the central repository that everybody uses. This
means that you can automatically `require` any package that is available there.

If you go to the [Packagist website](https://packagist.org/) (packagist.org),
you can browse and search for packages.

Any open source project using Composer is recommended to publish their packages
on Packagist. A library doesn't need to be on Packagist to be used by Composer,
but it enables discovery and adoption by other developers more quickly.

## Autoloading

For libraries that specify autoload information, Composer generates a
`vendor/autoload.php` file. You can simply include this file and you will get
autoloading for free.

```php
require __DIR__ . '/vendor/autoload.php';
```

This makes it really easy to use third party code. For example: If your project
depends on Monolog, you can just start using classes from it, and they will be
autoloaded.

```php
$log = new Monolog\Logger('name');
$log->pushHandler(new Monolog\Handler\StreamHandler('app.log', Monolog\Logger::WARNING));
$log->addWarning('Foo');
```

You can even add your own code to the autoloader by adding an
[`autoload`](04-schema.md#autoload) field to `composer.json`.

```json
{
    "autoload": {
        "psr-4": {"Acme\\": "src/"}
    }
}
```

Composer will register a [PSR-4](http://www.php-fig.org/psr/psr-4/) autoloader
for the `Acme` namespace.

You define a mapping from namespaces to directories. The `src` directory would
be in your project root, on the same level as `vendor` directory is. An example
filename would be `src/Foo.php` containing an `Acme\Foo` class.

After adding the [`autoload`](04-schema.md#autoload) field, you have to re-run
[`dump-autoload`](03-cli.md#dump-autoload) to re-generate the
`vendor/autoload.php` file.

Including that file will also return the autoloader instance, so you can store
the return value of the include call in a variable and add more namespaces.
This can be useful for autoloading classes in a test suite, for example.

```php
$loader = require __DIR__ . '/vendor/autoload.php';
$loader->add('Acme\\Test\\', __DIR__);
```

In addition to PSR-4 autoloading, Composer also supports PSR-0, classmap and
files autoloading. See the [`autoload`](04-schema.md#autoload) reference for
more information.

> **Note:** Composer provides its own autoloader. If you don't want to use that
> one, you can just include `vendor/composer/autoload_*.php` files, which return
> associative arrays allowing you to configure your own autoloader.

&larr; [Intro](00-intro.md)  |  [Libraries](02-libraries.md) &rarr;
