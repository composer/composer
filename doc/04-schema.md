# composer.json

This chapter will explain all of the fields available in `composer.json`.

## JSON schema

We have a [JSON schema](http://json-schema.org) that documents the format and
can also be used to validate your `composer.json`. In fact, it is used by the
`validate` command. You can find it at:
[`res/composer-schema.json`](https://github.com/composer/composer/blob/master/res/composer-schema.json).

## Root Package

The root package is the package defined by the `composer.json` at the root of
your project. It is the main `composer.json` that defines your project
requirements.

Certain fields only apply when in the root package context. One example of
this is the `config` field. Only the root package can define configuration.
The config of dependencies is ignored. This makes the `config` field
`root-only`.

If you clone one of those dependencies to work on it, then that package is the
root package. The `composer.json` is identical, but the context is different.

## Properties

### name

The name of the package. It consists of vendor name and project name,
separated by `/`.

Examples:

* monolog/monolog
* igorw/event-source

Required for published packages (libraries).

### description

A short description of the package. Usually this is just one line long.

Required for published packages (libraries).

### version

The version of the package.

This must follow the format of `X.Y.Z` with an optional suffix of `-dev`,
`alphaN`, `-betaN` or `-RCN`.

Examples:

    1.0.0
    1.0.2
    1.1.0
    0.2.5
    1.0.0-dev
    1.0.0-beta2
    1.0.0-RC5

Optional if the package repository can infer the version from somewhere, such
as the VCS tag name in the VCS repository. In that case it is also recommended
to omit it.

### type

The type of the package. It defaults to `library`.

Package types are used for custom installation logic. If you have a package
that needs some special logic, you can define a custom type. This could be a
`symfony-bundle`, a `wordpress-plugin` or a `typo3-module`. These types will
all be specific to certain projects, and they will need to provide an
installer capable of installing packages of that type.

Out of the box, composer supports two types:

- **library:** This is the default. It will simply copy the files to `vendor`.
- **metapackage:** An empty package that contains requirements and will trigger
  their installation, but contains no files and will not write anything to the
  filesystem. As such, it does not require a dist or source key to be
  installable.
- **composer-installer:** A package of type `composer-installer` provides an
  installer for other packages that have a custom type. Read more in the
  [dedicated article](articles/custom-installers.md).

Only use a custom type if you need custom logic during installation. It is
recommended to omit this field and have it just default to `library`.

### keywords

An array of keywords that the package is related to. These can be used for
searching and filtering.

Examples:

    logging
    events
    database
    redis
    templating

Optional.

### homepage

An URL to the website of the project.

Optional.

### time

Release date of the version.

Must be in `YYYY-MM-DD` or `YYYY-MM-DD HH:MM:SS` format.

Optional.

### license

The license of the package. This can be either a string or an array of strings.

The recommended notation for the most common licenses is:

    MIT
    BSD-2
    BSD-3
    BSD-4
    GPLv2
    GPLv3
    LGPLv2
    LGPLv3
    Apache2
    WTFPL

Optional, but it is highly recommended to supply this.

### authors

The authors of the package. This is an array of objects.

Each author object can have following properties:

* **name:** The author's name. Usually his real name.
* **email:** The author's email address.
* **homepage:** An URL to the author's website.

An example:

    {
        "authors": [
            {
                "name": "Nils Adermann",
                "email": "naderman@naderman.de",
                "homepage": "http://www.naderman.de"
            },
            {
                "name": "Jordi Boggiano",
                "email": "j.boggiano@seld.be",
                "homepage": "http://seld.be"
            }
        ]
    }

Optional, but highly recommended.

### Package links <span>(require, require-dev, conflict, replace, provide)</span>

Each of these takes an object which maps package names to version constraints.

* **require:** Packages required by this package.
* **require-dev:** Packages required for developing this package, or running
  tests, etc. They are installed if install or update is ran with `--dev`.
* **conflict:** Mark this version of this package as conflicting with other
  packages.
* **replace:** Packages that can be replaced by this package. This is useful
  for large repositories with subtree splits. It allows the main package to
  replace all of it's child packages.
* **provide:** List of other packages that are provided by this package. This
  is mostly useful for common interfaces. A package could depend on some virtual
  `logger` package, any library that provides this logger, would simply list it
  in `provide`.

Example:

    {
        "require": {
            "monolog/monolog": "1.0.*"
        }
    }

Optional.

### suggest

Suggested packages that can enhance or work well with this package. These are
just informational and are displayed after the package is installed, to give
your users a hint that they could add more packages, even though they are not
strictly required.

The format is like package links above, except that the values are free text
and not version constraints.

Example:

    {
        "suggest": {
            "monolog/monolog": "Allows more advanced logging of the application flow"
        }
    }

### autoload

Autoload mapping for a PHP autoloader.

Currently [PSR-0](https://github.com/php-fig/fig-standards/blob/master/accepted/PSR-0.md)
autoloading and classmap generation are supported.

Under the `psr-0` key you define a mapping from namespaces to paths, relative to the
package root.

Example:

    {
        "autoload": {
            "psr-0": { "Monolog": "src/" }
        }
    }

Optional, but it is highly recommended that you follow PSR-0 and use this.
If you need to search for a same namespace prefix in multiple directories,
you can specify them as an array as such:

    {
        "autoload": {
            "psr-0": { "Monolog": ["src/", "lib/"] }
        }
    }

You can use the classmap generation support to define autoloading for all libraries
that do not follow PSR-0. To configure this you specify all directories
to search for classes.

Example:

    {
        "autoload: {
            "classmap": ["src/", "lib/"]
        }
    }

### include-path

> **DEPRECATED**: This is only present to support legacy projects, and all new code should preferably use autoloading.

A list of paths which should get appended to PHP's `include_path`.

Example:

    {
        "include-path": ["lib/"]
    }

Optional.

### target-dir

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

    {
        "autoload": {
            "psr-0": { "Symfony\\Component\\Yaml": "" }
        },
        "target-dir": "Symfony/Component/Yaml"
    }

Optional.

### repositories <span>(root-only)</span>

Custom package repositories to use.

By default composer just uses the packagist repository. By specifying
repositories you can get packages from elsewhere.

Repositories are not resolved recursively. You can only add them to your main
`composer.json`. Repository declarations of dependencies' `composer.json`s are
ignored.

The following repository types are supported:

* **composer:** A composer repository is simply a `packages.json` file served
  via HTTP, that contains a list of `composer.json` objects with additional
  `dist` and/or `source` information.
* **vcs:** The version control system repository can fetch packages from git,
  svn and hg repositories.
* **pear:** With this you can import any pear repository into your composer
  project.
* **package:** If you depend on a project that does not have any support for
  composer whatsoever you can define the package inline using a `package`
  repository. You basically just inline the `composer.json` object.

For more information on any of these, see [Repositories](05-repositories.md).

Example:

    {
        "repositories": [
            {
                "type": "composer",
                "url": "http://packages.example.com"
            },
            {
                "type": "vcs",
                "url": "https://github.com/Seldaek/monolog"
            },
            {
                "type": "pear",
                "url": "http://pear2.php.net"
            },
            {
                "type": "package",
                "package": {
                    "name": "smarty/smarty",
                    "version": "3.1.7",
                    "dist": {
                        "url": "http://www.smarty.net/files/Smarty-3.1.7.zip",
                        "type": "zip"
                    },
                    "source": {
                        "url": "http://smarty-php.googlecode.com/svn/",
                        "type": "svn",
                        "reference": "tags/Smarty_3_1_7/distribution/"
                    }
                }
            }
        ]
    }

> **Note:** Order is significant here. When looking for a package, Composer
will look from the first to the last repository, and pick the first match.
By default Packagist is added last which means that custom repositories can
override packages from it.

### config <span>(root-only)</span>

A set of configuration options. It is only used for projects.

The following options are supported:

* **vendor-dir:** Defaults to `vendor`. You can install dependencies into a
  different directory if you want to.
* **bin-dir:** Defaults to `vendor/bin`. If a project includes binaries, they
  will be symlinked into this directory.
* **process-timeout:** Defaults to `300`. The duration processes like git clones
  can run before Composer assumes they died out. You may need to make this
  higher if you have a slow connection or huge vendors.
* **notify-on-install:** Defaults to `true`. Composer allows repositories to
  define a notification URL, so that they get notified whenever a package is
  installed. This option allows you to disable that behaviour.

Example:

    {
        "config": {
            "bin-dir": "bin"
        }
    }

### scripts <span>(root-only)</span>

Composer allows you to hook into various parts of the installation process
through the use of scripts.

See [Scripts](articles/scripts.md) for events details and examples.

### extra

Arbitrary extra data for consumption by `scripts`.

This can be virtually anything. To access it from within a script event
handler, you can do:

    $extra = $event->getComposer()->getPackage()->getExtra();

Optional.

### bin

A set of files that should be treated as binaries and symlinked into the `bin-dir`
(from config).

See [Vendor Bins](articles/vendor-bins.md) for more details.

Optional.

&larr; [Command-line interface](03-cli.md)  |  [Repositories](05-repositories.md) &rarr;
