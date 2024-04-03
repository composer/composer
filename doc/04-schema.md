# The composer.json schema

This chapter will explain all of the fields available in `composer.json`.

## JSON schema

We have a [JSON schema](https://json-schema.org) that documents the format and
can also be used to validate your `composer.json`. In fact, it is used by the
`validate` command. You can find it at: https://getcomposer.org/schema.json

## Root Package

The root package is the package defined by the `composer.json` at the root of
your project. It is the main `composer.json` that defines your project
requirements.

Certain fields only apply when in the root package context. One example of
this is the `config` field. Only the root package can define configuration.
The config of dependencies is ignored. This makes the `config` field
`root-only`.

> **Note:** A package can be the root package or not, depending on the context.
> For example, if your project depends on the `monolog` library, your project
> is the root package. However, if you clone `monolog` from GitHub in order to
> fix a bug in it, then `monolog` is the root package.

## Properties

### name

The name of the package. It consists of vendor name and project name,
separated by `/`. Examples:

* monolog/monolog
* igorw/event-source

The name must be lowercase and consist of words separated by `-`, `.` or `_`.
The complete name should match `^[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9](([_.]|-{1,2})?[a-z0-9]+)*$`.

The `name` property is required for published packages (libraries).

> **Note:** Before Composer version 2.0, a name could contain any character, including white spaces.

### description

A short description of the package. Usually this is one line long.

Required for published packages (libraries).

### version

The version of the package. In most cases this is not required and should
be omitted (see below).

This must follow the format of `X.Y.Z` or `vX.Y.Z` with an optional suffix
of `-dev`, `-patch` (`-p`), `-alpha` (`-a`), `-beta` (`-b`) or `-RC`.
The patch, alpha, beta and RC suffixes can also be followed by a number.

Examples:

- 1.0.0
- 1.0.2
- 1.1.0
- 0.2.5
- 1.0.0-dev
- 1.0.0-alpha3
- 1.0.0-beta2
- 1.0.0-RC5
- v2.0.4-p1

Optional if the package repository can infer the version from somewhere, such
as the VCS tag name in the VCS repository. In that case it is also recommended
to omit it.

> **Note:** Packagist uses VCS repositories, so the statement above is very
> much true for Packagist as well. Specifying the version yourself will
> most likely end up creating problems at some point due to human error.

### type

The type of the package. It defaults to `library`.

Package types are used for custom installation logic. If you have a package
that needs some special logic, you can define a custom type. This could be a
`symfony-bundle`, a `wordpress-plugin` or a `typo3-cms-extension`. These types
will all be specific to certain projects, and they will need to provide an
installer capable of installing packages of that type.

Out of the box, Composer supports four types:

- **library:** This is the default. It will copy the files to `vendor`.
- **project:** This denotes a project rather than a library. For example
  application shells like the [Symfony standard edition](https://github.com/symfony/symfony-standard),
  CMSs like the [Silverstripe installer](https://github.com/silverstripe/silverstripe-installer)
  or full fledged applications distributed as packages. This can for example
  be used by IDEs to provide listings of projects to initialize when creating
  a new workspace.
- **metapackage:** An empty package that contains requirements and will trigger
  their installation, but contains no files and will not write anything to the
  filesystem. As such, it does not require a dist or source key to be
  installable.
- **composer-plugin:** A package of type `composer-plugin` may provide an
  installer for other packages that have a custom type. Read more in the
  [dedicated article](articles/custom-installers.md).
- **php-ext** and **php-ext-zend**: These names are reserved for PHP extension
  packages which are written in C. Do not use these types for packages written
  in PHP.

Only use a custom type if you need custom logic during installation. It is
recommended to omit this field and have it default to `library`.

### keywords

An array of keywords that the package is related to. These can be used for
searching and filtering.

Examples:

- logging
- events
- database
- redis
- templating

> **Note**: Some special keywords trigger `composer require` without the
> `--dev` option to prompt users if they would like to add these packages to
> `require-dev` instead of `require`. These are: `dev`, `testing`, `static analysis`.

> **Note**: The range of characters allowed inside the string is restricted to
> unicode letters or numbers, space `" "`, dot `.`, underscore `_` and dash `-`. (Regex: `'{^[\p{N}\p{L} ._-]+$}u'`)
> Using other characters will emit a warning when running `composer validate` and
> will cause the package to fail updating on Packagist.org.

Optional.

### homepage

A URL to the website of the project.

Optional.

### readme

A relative path to the readme document. Defaults to `README.md`.

This is mainly useful for packages not on GitHub, as for GitHub packages Packagist.org will use the readme API to fetch the one detected by GitHub.

Optional.

### time

Release date of the version.

Must be in `YYYY-MM-DD` or `YYYY-MM-DD HH:MM:SS` format.

Optional.

### license

The license of the package. This can be either a string or an array of strings.

The recommended notation for the most common licenses is (alphabetical):

- Apache-2.0
- BSD-2-Clause
- BSD-3-Clause
- BSD-4-Clause
- GPL-2.0-only / GPL-2.0-or-later
- GPL-3.0-only / GPL-3.0-or-later
- LGPL-2.1-only / LGPL-2.1-or-later
- LGPL-3.0-only / LGPL-3.0-or-later
- MIT

Optional, but it is highly recommended to supply this. More identifiers are
listed at the [SPDX Open Source License Registry](https://spdx.org/licenses/).

> **Note:** For closed-source software, you may use `"proprietary"` as the license identifier.

An Example:

```json
{
    "license": "MIT"
}
```

For a package, when there is a choice between licenses ("disjunctive license"),
multiple can be specified as an array.

An Example for disjunctive licenses:

```json
{
    "license": [
        "LGPL-2.1-only",
        "GPL-3.0-or-later"
    ]
}
```

Alternatively they can be separated with "or" and enclosed in parentheses;

```json
{
    "license": "(LGPL-2.1-only or GPL-3.0-or-later)"
}
```

Similarly, when multiple licenses need to be applied ("conjunctive license"),
they should be separated with "and" and enclosed in parentheses.

### authors

The authors of the package. This is an array of objects.

Each author object can have following properties:

* **name:** The author's name. Usually their real name.
* **email:** The author's email address.
* **homepage:** URL to the author's website.
* **role:** The author's role in the project (e.g. developer or translator)

An example:

```json
{
    "authors": [
        {
            "name": "Nils Adermann",
            "email": "naderman@naderman.de",
            "homepage": "https://www.naderman.de",
            "role": "Developer"
        },
        {
            "name": "Jordi Boggiano",
            "email": "j.boggiano@seld.be",
            "homepage": "https://seld.be",
            "role": "Developer"
        }
    ]
}
```

Optional, but highly recommended.

### support

Various information to get support about the project.

Support information includes the following:

* **email:** Email address for support.
* **issues:** URL to the issue tracker.
* **forum:** URL to the forum.
* **wiki:** URL to the wiki.
* **irc:** IRC channel for support, as irc://server/channel.
* **source:** URL to browse or download the sources.
* **docs:** URL to the documentation.
* **rss:** URL to the RSS feed.
* **chat:** URL to the chat channel.
* **security:** URL to the vulnerability disclosure policy (VDP).

An example:

```json
{
    "support": {
        "email": "support@example.org",
        "irc": "irc://irc.freenode.org/composer"
    }
}
```

Optional.

### funding

A list of URLs to provide funding to the package authors for maintenance and
development of new functionality.

Each entry consists of the following

* **type:** The type of funding, or the platform through which funding can be provided, e.g. patreon, opencollective, tidelift or github.
* **url:** URL to a website with details, and a way to fund the package.

An example:

```json
{
    "funding": [
        {
            "type": "patreon",
            "url": "https://www.patreon.com/phpdoctrine"
        },
        {
            "type": "tidelift",
            "url": "https://tidelift.com/subscription/pkg/packagist-doctrine_doctrine-bundle"
        },
        {
            "type": "other",
            "url": "https://www.doctrine-project.org/sponsorship.html"
        }
    ]
}
```

Optional.

### Package links

All of the following take an object which maps package names to
versions of the package via version constraints. Read more about
versions [here](articles/versions.md).

Example:

```json
{
    "require": {
        "monolog/monolog": "1.0.*"
    }
}
```

All links are optional fields.

`require` and `require-dev` additionally support _stability flags_ ([root-only](04-schema.md#root-package)).
They take the form "_constraint_@_stability flag_".
These allow you to further restrict or expand the stability of a package beyond
the scope of the [minimum-stability](#minimum-stability) setting. You can apply
them to a constraint, or apply them to an empty _constraint_ if you want to
allow unstable packages of a dependency for example.

Example:

```json
{
    "require": {
        "monolog/monolog": "1.0.*@beta",
        "acme/foo": "@dev"
    }
}
```

If one of your dependencies has a dependency on an unstable package you need to
explicitly require it as well, along with its sufficient stability flag.

Example:

Assuming `doctrine/doctrine-fixtures-bundle` requires `"doctrine/data-fixtures": "dev-master"`
then inside the root composer.json you need to add the second line below to allow dev
releases for the `doctrine/data-fixtures` package :

```json
{
    "require": {
        "doctrine/doctrine-fixtures-bundle": "dev-master",
        "doctrine/data-fixtures": "@dev"
    }
}
```

`require` and `require-dev` additionally support explicit references (i.e.
commit) for dev versions to make sure they are locked to a given state, even
when you run update. These only work if you explicitly require a dev version
and append the reference with `#<ref>`. This is also a
[root-only](04-schema.md#root-package) feature and will be ignored in
dependencies.

Example:

```json
{
    "require": {
        "monolog/monolog": "dev-master#2eb0c0978d290a1c45346a1955188929cb4e5db7",
        "acme/foo": "1.0.x-dev#abc123"
    }
}
```

> **Note:** This feature has severe technical limitations, as the
> composer.json metadata will still be read from the branch name you specify
> before the hash. You should therefore only use this as a temporary solution
> during development to remediate transient issues, until you can switch to
> tagged releases. The Composer team does not actively support this feature
> and will not accept bug reports related to it.

It is also possible to inline-alias a package constraint so that it matches
a constraint that it otherwise would not. For more information [see the
aliases article](articles/aliases.md).

`require` and `require-dev` also support references to specific PHP versions
and PHP extensions your project needs to run successfully.

Example:

```json
{
    "require": {
        "php": ">=7.4",
        "ext-mbstring": "*"
    }
}
```

> **Note:** It is important to list PHP extensions your project requires.
> Not all PHP installations are created equal: some may miss extensions you
> may consider as standard (such as `ext-mysqli` which is not installed by
> default in Fedora/CentOS minimal installation systems). Failure to list
> required PHP extensions may lead to a bad user experience: Composer will
> install your package without any errors but it will then fail at run-time.
> The `composer show --platform` command lists all PHP extensions available on
> your system. You may use it to help you compile the list of extensions you
> use and require. Alternatively you may use third party tools to analyze
> your project for the list of extensions used.

#### require

Map of packages required by this package. The package will not be installed
unless those requirements can be met.

#### require-dev <span>([root-only](04-schema.md#root-package))</span>

Map of packages required for developing this package, or running
tests, etc. The dev requirements of the root package are installed by default.
Both `install` or `update` support the `--no-dev` option that prevents dev
dependencies from being installed.

#### conflict

Map of packages that conflict with this version of this package. They
will not be allowed to be installed together with your package.

Note that when specifying ranges like `<1.0 >=1.1` in a `conflict` link,
this will state a conflict with all versions that are less than 1.0 *and* equal
or newer than 1.1 at the same time, which is probably not what you want. You
probably want to go for `<1.0 || >=1.1` in this case.

#### replace

Map of packages that are replaced by this package. This allows you to fork a
package, publish it under a different name with its own version numbers, while
packages requiring the original package continue to work with your fork because
it replaces the original package.

This is also useful for packages that contain sub-packages, for example the main
symfony/symfony package contains all the Symfony Components which are also
available as individual packages. If you require the main package it will
automatically fulfill any requirement of one of the individual components,
since it replaces them.

Caution is advised when using replace for the sub-package purpose explained
above. You should then typically only replace using `self.version` as a version
constraint, to make sure the main package only replaces the sub-packages of
that exact version, and not any other version, which would be incorrect.

#### provide

Map of packages that are provided by this package. This is mostly
useful for implementations of common interfaces. A package could depend on
some virtual package e.g. `psr/log-implementation`, any library that implements
this logger interface would list it in `provide`. Implementors can then
be [found on Packagist.org](https://packagist.org/providers/psr/log-implementation).

Using `provide` with the name of an actual package rather than a virtual one
implies that the code of that package is also shipped, in which case `replace`
is generally a better choice. A common convention for packages providing an
interface and relying on other packages to provide an implementation (for
instance the PSR interfaces) is to use a `-implementation` suffix for the
name of the virtual package corresponding to the interface package.

#### suggest

Suggested packages that can enhance or work well with this package. These are
informational and are displayed after the package is installed, to give
your users a hint that they could add more packages, even though they are not
strictly required.

The format is like package links above, except that the values are free text
and not version constraints.

Example:

```json
{
    "suggest": {
        "monolog/monolog": "Allows more advanced logging of the application flow",
        "ext-xml": "Needed to support XML format in class Foo"
    }
}
```

### autoload

Autoload mapping for a PHP autoloader.

[`PSR-4`](https://www.php-fig.org/psr/psr-4/) and [`PSR-0`](http://www.php-fig.org/psr/psr-0/)
autoloading, `classmap` generation and `files` includes are supported.

PSR-4 is the recommended way since it offers greater ease of use (no need
to regenerate the autoloader when you add classes).

#### PSR-4

Under the `psr-4` key you define a mapping from namespaces to paths, relative to the
package root. When autoloading a class like `Foo\\Bar\\Baz` a namespace prefix
`Foo\\` pointing to a directory `src/` means that the autoloader will look for a
file named `src/Bar/Baz.php` and include it if present. Note that as opposed to
the older PSR-0 style, the prefix (`Foo\\`) is **not** present in the file path.

Namespace prefixes must end in `\\` to avoid conflicts between similar prefixes.
For example `Foo` would match classes in the `FooBar` namespace so the trailing
backslashes solve the problem: `Foo\\` and `FooBar\\` are distinct.

The PSR-4 references are all combined, during install/update, into a single
key => value array which may be found in the generated file
`vendor/composer/autoload_psr4.php`.

Example:

```json
{
    "autoload": {
        "psr-4": {
            "Monolog\\": "src/",
            "Vendor\\Namespace\\": ""
        }
    }
}
```

If you need to search for a same prefix in multiple directories,
you can specify them as an array as such:

```json
{
    "autoload": {
        "psr-4": { "Monolog\\": ["src/", "lib/"] }
    }
}
```

If you want to have a fallback directory where any namespace will be looked for,
you can use an empty prefix like:

```json
{
    "autoload": {
        "psr-4": { "": "src/" }
    }
}
```

#### PSR-0

Under the `psr-0` key you define a mapping from namespaces to paths, relative to the
package root. Note that this also supports the PEAR-style non-namespaced convention.

Please note namespace declarations should end in `\\` to make sure the autoloader
responds exactly. For example `Foo` would match in `FooBar` so the trailing
backslashes solve the problem: `Foo\\` and `FooBar\\` are distinct.

The PSR-0 references are all combined, during install/update, into a single key => value
array which may be found in the generated file `vendor/composer/autoload_namespaces.php`.

Example:

```json
{
    "autoload": {
        "psr-0": {
            "Monolog\\": "src/",
            "Vendor\\Namespace\\": "src/",
            "Vendor_Namespace_": "src/"
        }
    }
}
```

If you need to search for a same prefix in multiple directories,
you can specify them as an array as such:

```json
{
    "autoload": {
        "psr-0": { "Monolog\\": ["src/", "lib/"] }
    }
}
```

The PSR-0 style is not limited to namespace declarations only but may be
specified right down to the class level. This can be useful for libraries with
only one class in the global namespace. If the php source file is also located
in the root of the package, for example, it may be declared like this:

```json
{
    "autoload": {
        "psr-0": { "UniqueGlobalClass": "" }
    }
}
```

If you want to have a fallback directory where any namespace can be, you can
use an empty prefix like:

```json
{
    "autoload": {
        "psr-0": { "": "src/" }
    }
}
```

#### Classmap

The `classmap` references are all combined, during install/update, into a single
key => value array which may be found in the generated file
`vendor/composer/autoload_classmap.php`. This map is built by scanning for
classes in all `.php` and `.inc` files in the given directories/files.

You can use the classmap generation support to define autoloading for all libraries
that do not follow PSR-0/4. To configure this you specify all directories or files
to search for classes.

Example:

```json
{
    "autoload": {
        "classmap": ["src/", "lib/", "Something.php"]
    }
}
```

Wildcards (`*`) are also supported in a classmap paths, and expand to match any directory name:

Example:

```json
{
    "autoload": {
        "classmap": ["src/addons/*/lib/", "3rd-party/*", "Something.php"]
    }
}
```

#### Files

If you want to require certain files explicitly on every request then you can use
the `files` autoloading mechanism. This is useful if your package includes PHP functions
that cannot be autoloaded by PHP.

Example:

```json
{
    "autoload": {
        "files": ["src/MyLibrary/functions.php"]
    }
}
```

Files autoload rules are included whenever `vendor/autoload.php` is included, right after
the autoloader is registered. The order of inclusion depends on package dependencies so that
if package A depends on B, files in package B will be included first to ensure package B is fully
initialized and ready to be used when files from package A are included.

If two packages have the same amount of dependents or no dependencies, the order is alphabetical.

Files from the root package are always loaded last, and you cannot use files autoloading
yourself to override functions from your dependencies. If you want to achieve that we recommend
you include your own functions *before* including Composer's `vendor/autoload.php`.

#### Exclude files from classmaps

If you want to exclude some files or folders from the classmap you can use the `exclude-from-classmap` property.
This might be useful to exclude test classes in your live environment, for example, as those will be skipped
from the classmap even when building an optimized autoloader.

The classmap generator will ignore all files in the paths configured here. The paths are absolute from the package
root directory (i.e. composer.json location), and support `*` to match anything but a slash, and `**` to
match anything. `**` is implicitly added to the end of the paths.

Example:

```json
{
    "autoload": {
        "exclude-from-classmap": ["/Tests/", "/test/", "/tests/"]
    }
}
```

#### Optimizing the autoloader

The autoloader can have quite a substantial impact on your request time
(50-100ms per request in large frameworks using a lot of classes). See the
[article about optimizing the autoloader](articles/autoloader-optimization.md)
for more details on how to reduce this impact.

### autoload-dev <span>([root-only](04-schema.md#root-package))</span>

This section allows defining autoload rules for development purposes.

Classes needed to run the test suite should not be included in the main autoload
rules to avoid polluting the autoloader in production and when other people use
your package as a dependency.

Therefore, it is a good idea to rely on a dedicated path for your unit tests
and to add it within the autoload-dev section.

Example:

```json
{
    "autoload": {
        "psr-4": { "MyLibrary\\": "src/" }
    },
    "autoload-dev": {
        "psr-4": { "MyLibrary\\Tests\\": "tests/" }
    }
}
```

### include-path

> **DEPRECATED**: This is only present to support legacy projects, and all new code
> should preferably use autoloading. As such it is a deprecated practice, but the
> feature itself will not likely disappear from Composer.

A list of paths which should get appended to PHP's `include_path`.

Example:

```json
{
    "include-path": ["lib/"]
}
```

Optional.

### target-dir

> **DEPRECATED**: This is only present to support legacy PSR-0 style autoloading,
> and all new code should preferably use PSR-4 without target-dir and projects
> using PSR-0 with PHP namespaces are encouraged to migrate to PSR-4 instead.

Defines the installation target.

In case the package root is below the namespace declaration you cannot
autoload properly. `target-dir` solves this problem.

An example is Symfony. There are individual packages for the components. The
Yaml component is under `Symfony\Component\Yaml`. The package root is that
`Yaml` directory. To make autoloading possible, we need to make sure that it
is not installed into `vendor/symfony/yaml`, but instead into
`vendor/symfony/yaml/Symfony/Component/Yaml`, so that the autoloader can load
it from `vendor/symfony/yaml`.

To do that, `autoload` and `target-dir` are defined as follows:

```json
{
    "autoload": {
        "psr-0": { "Symfony\\Component\\Yaml\\": "" }
    },
    "target-dir": "Symfony/Component/Yaml"
}
```

Optional.

### minimum-stability <span>([root-only](04-schema.md#root-package))</span>

This defines the default behavior for filtering packages by stability. This
defaults to `stable`, so if you rely on a `dev` package, you should specify
it in your file to avoid surprises.

All versions of each package are checked for stability, and those that are less
stable than the `minimum-stability` setting will be ignored when resolving
your project dependencies. (Note that you can also specify stability requirements
on a per-package basis using stability flags in the version constraints that you
specify in a `require` block (see [package links](#package-links) for more details).

Available options (in order of stability) are `dev`, `alpha`, `beta`, `RC`,
and `stable`.

### prefer-stable <span>([root-only](04-schema.md#root-package))</span>

When this is enabled, Composer will prefer more stable packages over unstable
ones when finding compatible stable packages is possible. If you require a
dev version or only alphas are available for a package, those will still be
selected granted that the minimum-stability allows for it.

Use `"prefer-stable": true` to enable.

### repositories <span>([root-only](04-schema.md#root-package))</span>

Custom package repositories to use.

By default Composer only uses the packagist repository. By specifying
repositories you can get packages from elsewhere.

Repositories are not resolved recursively. You can only add them to your main
`composer.json`. Repository declarations of dependencies' `composer.json`s are
ignored.

The following repository types are supported:

* **composer:** A Composer repository is a `packages.json` file served
  via the network (HTTP, FTP, SSH), that contains a list of `composer.json`
  objects with additional `dist` and/or `source` information. The `packages.json`
  file is loaded using a PHP stream. You can set extra options on that stream
  using the `options` parameter.
* **vcs:** The version control system repository can fetch packages from git,
  svn, fossil and hg repositories.
* **package:** If you depend on a project that does not have any support for
  Composer whatsoever you can define the package inline using a `package`
  repository. You basically inline the `composer.json` object.

For more information on any of these, see [Repositories](05-repositories.md).

Example:

```json
{
    "repositories": [
        {
            "type": "composer",
            "url": "http://packages.example.com"
        },
        {
            "type": "composer",
            "url": "https://packages.example.com",
            "options": {
                "ssl": {
                    "verify_peer": "true"
                }
            }
        },
        {
            "type": "vcs",
            "url": "https://github.com/Seldaek/monolog"
        },
        {
            "type": "package",
            "package": {
                "name": "smarty/smarty",
                "version": "3.1.7",
                "dist": {
                    "url": "https://www.smarty.net/files/Smarty-3.1.7.zip",
                    "type": "zip"
                },
                "source": {
                    "url": "https://smarty-php.googlecode.com/svn/",
                    "type": "svn",
                    "reference": "tags/Smarty_3_1_7/distribution/"
                }
            }
        }
    ]
}
```

> **Note:** Order is significant here. When looking for a package, Composer
will look from the first to the last repository, and pick the first match.
By default Packagist is added last which means that custom repositories can
override packages from it.

Using JSON object notation is also possible. However, JSON key/value pairs
are to be considered unordered so consistent behaviour cannot be guaranteed.

```json
{
    "repositories": {
        "foo": {
            "type": "composer",
            "url": "http://packages.foo.com"
        }
    }
}
```

### config <span>([root-only](04-schema.md#root-package))</span>

A set of configuration options. It is only used for projects. See
[Config](06-config.md) for a description of each individual option.

### scripts <span>([root-only](04-schema.md#root-package))</span>

Composer allows you to hook into various parts of the installation process
through the use of scripts.

See [Scripts](articles/scripts.md) for events details and examples.

### extra

Arbitrary extra data for consumption by `scripts`.

This can be virtually anything. To access it from within a script event
handler, you can do:

```php
$extra = $event->getComposer()->getPackage()->getExtra();
```

Optional.

### bin

A set of files that should be treated as binaries and made available
into the `bin-dir` (from config).

See [Vendor Binaries](articles/vendor-binaries.md) for more details.

Optional.

### archive

A set of options for creating package archives.

The following options are supported:

* **name:** Allows configuring base name for archive.
  By default (if not configured, and `--file` is not passed as command-line argument),
  `preg_replace('#[^a-z0-9-_]#i', '-', name)` is used.

Example:

```json
{
    "name": "org/strangeName",
    "archive": {
        "name": "Strange_name"
    }
}
```

* **exclude:** Allows configuring a list of patterns for excluded paths. The
  pattern syntax matches .gitignore files. A leading exclamation mark (!) will
  result in any matching files to be included even if a previous pattern
  excluded them. A leading slash will only match at the beginning of the project
  relative path. An asterisk will not expand to a directory separator.

Example:

```json
{
    "archive": {
        "exclude": ["/foo/bar", "baz", "/*.test", "!/foo/bar/baz"]
    }
}
```

The example will include `/dir/foo/bar/file`, `/foo/bar/baz`, `/file.php`,
`/foo/my.test` but it will exclude `/foo/bar/any`, `/foo/baz`, and `/my.test`.

Optional.

### abandoned

Indicates whether this package has been abandoned.

It can be boolean or a package name/URL pointing to a recommended alternative.

Examples:

Use `"abandoned": true` to indicate this package is abandoned.
Use `"abandoned": "monolog/monolog"` to indicate this package is abandoned, and that
the recommended alternative is `monolog/monolog`.

Defaults to false.

Optional.

### _comment

Top level key used as a place to store comments (it can be a string or array of strings).

```json
{
    "_comment": [
        "The package foo/bar was required for business logic",
        "Remove package foo/baz when removing foo/bar"
    ]
}
```

Defaults to empty.

Optional.

### non-feature-branches

A list of regex patterns of branch names that are non-numeric (e.g. "latest" or something),
that will NOT be handled as feature branches. This is an array of strings.

If you have non-numeric branch names, for example like "latest", "current", "latest-stable"
or something, that do not look like a version number, then Composer handles such branches
as feature branches. This means it searches for parent branches, that look like a version
or ends at special branches (like master), and the root package version number becomes the
version of the parent branch or at least master or something.

To handle non-numeric named branches as versions instead of searching for a parent branch
with a valid version or special branch name like master, you can set patterns for branch
names that should be handled as dev version branches.

This is really helpful when you have dependencies using "self.version", so that not dev-master,
but the same branch is installed (in the example: latest-testing).

An example:

If you have a testing branch, that is heavily maintained during a testing phase and is
deployed to your staging environment, normally `composer show -s` will give you `versions : * dev-master`.

If you configure `latest-.*` as a pattern for non-feature-branches like this:

```json
{
    "non-feature-branches": ["latest-.*"]
}
```

Then `composer show -s` will give you `versions : * dev-latest-testing`.

Optional.

&larr; [Command-line interface](03-cli.md)  |  [Repositories](05-repositories.md) &rarr;
