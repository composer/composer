# Basic usage

## Installing

If you have not yet installed Composer, refer to the [Intro](00-intro.md) chapter.

## `composer.json`: Project Setup

To start using Composer in your project, all you need is a `composer.json`
file. This file describes the dependencies of your project and may contain
other metadata as well.

The [JSON format](http://json.org/) is quite easy to write. It allows you to
define nested structures.

### The `require` Key

The first (and often only) thing you specify in `composer.json` is the
`require` key. You're simply telling Composer which packages your project
depends on.

```json
{
    "require": {
        "monolog/monolog": "1.0.*"
    }
}
```

As you can see, `require` takes an object that maps **package names** (e.g. `monolog/monolog`)
to **package versions** (e.g. `1.0.*`).

### Package Names

The package name consists of a vendor name and the project's name. Often these
will be identical - the vendor name just exists to prevent naming clashes. It allows
two different people to create a library named `json`, which would then just be
named `igorw/json` and `seldaek/json`.

Here we are requiring `monolog/monolog`, so the vendor name is the same as the
project's name. For projects with a unique name this is recommended. It also
allows adding more related projects under the same namespace later on. If you
are maintaining a library, this would make it really easy to split it up into
smaller decoupled parts.

### Package Versions

In the previous example we were requiring version `1.0.*` of monolog. This
means any version in the `1.0` development branch. It would match `1.0.0`,
`1.0.2` or `1.0.20`.

Version constraints can be specified in a few different ways.

Name           | Example                                                                  | Description
-------------- | ------------------------------------------------------------------------ | -----------
Exact version  | `1.0.2`                                                                  | You can specify the exact version of a package.
Range          | `>=1.0` `>=1.0 <2.0` <code>&gt;=1.0 &lt;1.1 &#124;&#124; &gt;=1.2</code> | By using comparison operators you can specify ranges of valid versions. Valid operators are `>`, `>=`, `<`, `<=`, `!=`. <br />You can define multiple ranges. Ranges separated by a space (<code> </code>) or comma (`,`) will be treated as a **logical AND**. A double pipe (<code>&#124;&#124;</code>) will be treated as a **logical OR**. AND has higher precedence than OR.
Hyphen Range   | `1.0 - 2.0`                                                              | Inclusive set of versions. Partial versions on the right include are completed with a wildcard. For example `1.0 - 2.0` is equivalent to `>=1.0.0 <2.1` as the `2.0` becomes `2.0.*`. On the other hand `1.0.0 - 2.1.0` is equivalent to `>=1.0.0 <=2.1.0`.
Wildcard       | `1.0.*`                                                                  | You can specify a pattern with a `*` wildcard. `1.0.*` is the equivalent of `>=1.0 <1.1`.
Tilde Operator | `~1.2`                                                                   | Very useful for projects that follow semantic versioning. `~1.2` is equivalent to `>=1.2 <2.0`. For more details, read the next section below.
Caret Operator | `^1.2.3`                                                                 | Very useful for projects that follow semantic versioning. `^1.2.3` is equivalent to `>=1.2.3 <2.0`. For more details, read the next section below.

### Next Significant Release (Tilde and Caret Operators)

The `~` operator is best explained by example: `~1.2` is equivalent to
`>=1.2 <2.0.0`, while `~1.2.3` is equivalent to `>=1.2.3 <1.3.0`. As you can see
it is mostly useful for projects respecting [semantic
versioning](http://semver.org/). A common usage would be to mark the minimum
minor version you depend on, like `~1.2` (which allows anything up to, but not
including, 2.0). Since in theory there should be no backwards compatibility
breaks until 2.0, that works well. Another way of looking at it is that using
`~` specifies a minimum version, but allows the last digit specified to go up.

The `^` operator behaves very similarly but it sticks closer to semantic
versioning, and will always allow non-breaking updates. For example `^1.2.3`
is equivalent to `>=1.2.3 <2.0.0` as none of the releases until 2.0 should
break backwards compatibility. For pre-1.0 versions it also acts with safety
in mind and treats `^0.3` as `>=0.3.0 <0.4.0`

> **Note:** Though `2.0-beta.1` is strictly before `2.0`, a version constraint
> like `~1.2` would not install it. As said above `~1.2` only means the `.2`
> can change but the `1.` part is fixed.

> **Note:** The `~` operator has an exception on its behavior for the major
> release number. This means for example that `~1` is the same as `~1.0` as
> it will not allow the major number to increase trying to keep backwards
> compatibility.

### Stability

By default only stable releases are taken into consideration. If you would like
to also get RC, beta, alpha or dev versions of your dependencies you can do
so using [stability flags](04-schema.md#package-links). To change that for all
packages instead of doing per dependency you can also use the
[minimum-stability](04-schema.md#minimum-stability) setting.

## Installing Dependencies

To fetch the defined dependencies into your local project, just run the
`install` command of `composer.phar`.

```sh
php composer.phar install
```

This will find the latest version of `monolog/monolog` that matches the
supplied version constraint and download it into the `vendor` directory.
It's a convention to put third party code into a directory named `vendor`.
In case of monolog it will put it into `vendor/monolog/monolog`.

> **Tip:** If you are using git for your project, you probably want to add
> `vendor` into your `.gitignore`. You really don't want to add all of that
> code to your repository.

Another thing that the `install` command does is it adds a `composer.lock`
file into your project root.

## `composer.lock` - The Lock File

After installing the dependencies, Composer writes the list of the exact
versions it installed into a `composer.lock` file. This locks the project
to those specific versions.

**Commit your application's `composer.lock` (along with `composer.json`) into version control.**

This is important because the `install` command checks if a lock file is present,
and if it is, it downloads the versions specified there (regardless of what `composer.json`
says).

This means that anyone who sets up the project will download the exact
same version of the dependencies. Your CI server, production machines, other
developers in your team, everything and everyone runs on the same dependencies, which
mitigates the potential for bugs affecting only some parts of the deployments. Even if you
develop alone, in six months when reinstalling the project you can feel confident the
dependencies installed are still working even if your dependencies released
many new versions since then.

If no `composer.lock` file exists, Composer will read the dependencies and
versions from `composer.json` and  create the lock file after executing the `update` or the `install`
command.

This means that if any of the dependencies get a new version, you won't get the updates
automatically. To update to the new version, use `update` command. This will fetch
the latest matching versions (according to your `composer.json` file) and also update
the lock file with the new version.

```sh
php composer.phar update
```
> **Note:** Composer will display a Warning when executing an `install` command if 
 `composer.lock` and `composer.json` are not synchronized.
 
If you only want to install or update one dependency, you can whitelist them:

```sh
php composer.phar update monolog/monolog [...]
```

> **Note:** For libraries it is not necessarily recommended to commit the lock file,
> see also: [Libraries - Lock file](02-libraries.md#lock-file).

## Packagist

[Packagist](https://packagist.org/) is the main Composer repository. A Composer
repository is basically a package source: a place where you can get packages
from. Packagist aims to be the central repository that everybody uses. This
means that you can automatically `require` any package that is available
there.

If you go to the [packagist website](https://packagist.org/) (packagist.org),
you can browse and search for packages.

Any open source project using Composer should publish their packages on
packagist. A library doesn't need to be on packagist to be used by Composer,
but it makes life quite a bit simpler.

## Autoloading

For libraries that specify autoload information, Composer generates a
`vendor/autoload.php` file. You can simply include this file and you
will get autoloading for free.

```php
require 'vendor/autoload.php';
```

This makes it really easy to use third party code. For example: If your
project depends on monolog, you can just start using classes from it, and they
will be autoloaded.

```php
$log = new Monolog\Logger('name');
$log->pushHandler(new Monolog\Handler\StreamHandler('app.log', Monolog\Logger::WARNING));

$log->addWarning('Foo');
```

You can even add your own code to the autoloader by adding an `autoload` field
to `composer.json`.

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

After adding the `autoload` field, you have to re-run `dump-autoload` to re-generate
the `vendor/autoload.php` file.

Including that file will also return the autoloader instance, so you can store
the return value of the include call in a variable and add more namespaces.
This can be useful for autoloading classes in a test suite, for example.

```php
$loader = require 'vendor/autoload.php';
$loader->add('Acme\\Test\\', __DIR__);
```

In addition to PSR-4 autoloading, classmap is also supported. This allows
classes to be autoloaded even if they do not conform to PSR-4. See the
[autoload reference](04-schema.md#autoload) for more details.

> **Note:** Composer provides its own autoloader. If you don't want to use
that one, you can just include `vendor/composer/autoload_*.php` files,
which return associative arrays allowing you to configure your own autoloader.

&larr; [Intro](00-intro.md)  |  [Libraries](02-libraries.md) &rarr;
