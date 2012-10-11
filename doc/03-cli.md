# Command-line interface

You've already learned how to use the command-line interface to do some
things. This chapter documents all the available commands.

## init

In the [Libraries](02-libraries.md) chapter we looked at how to create a
`composer.json` by hand. There is also an `init` command available that makes
it a bit easier to do this.

When you run the command it will interactively ask you to fill in the fields,
while using some smart defaults.

    $ php composer.phar init

### Options

* **--no-interaction:** (**-n**) Run the command in non-interactive mode.
  The rest of these options only make sense when you are in this mode.
* **--name:** Name of the package.
* **--description:** Description of the package.
* **--author:** Author name of the package.
* **--homepage:** Homepage of the package.
* **--require:** Package to require with a version constraint. Should be
  in format `foo/bar:1.0.0`.
* **--require-dev:** Development requirements, see **--require**.

## install

The `install` command reads the `composer.json` file from the current
directory, resolves the dependencies, and installs them into `vendor`.

    $ php composer.phar install

If there is a `composer.lock` file in the current directory, it will use the
exact versions from there instead of resolving them. This ensures that
everyone using the library will get the same versions of the dependencies.

If there is no `composer.lock` file, composer will create one after dependency
resolution.

### Options

* **--prefer-source:** There are two ways of downloading a package: `source`
  and `dist`. For stable versions composer will use the `dist` by default.
  The `source` is a version control repository. If `--prefer-source` is
  enabled, composer will install from `source` if there is one. This is
  useful if you want to make a bugfix to a project and get a local git
  clone of the dependency directly.
* **--dry-run:** If you want to run through an installation without actually
  installing a package, you can use `--dry-run`. This will simulate the
  installation and show you what would happen.
* **--dev:** By default composer will only install required packages. By
  passing this option you can also make it install packages referenced by
  `require-dev`.
* **--no-scripts:** Skips execution of scripts defined in `composer.json`.

## update

In order to get the latest versions of the dependencies and to update the
`composer.lock` file, you should use the `update` command.

    $ php composer.phar update

This will resolve all dependencies of the project and write the exact versions
into `composer.lock`.

If you just want to update a few packages and not all, you can list them as such:

    $ php composer.phar update vendor/package vendor/package2

### Options

* **--prefer-source:** Install packages from `source` when available.
* **--dry-run:** Simulate the command without actually doing anything.
* **--dev:** Install packages listed in `require-dev`.
* **--no-scripts:** Skips execution of scripts defined in `composer.json`.

## require

The `require` command adds new packages to the `composer.json` file from
the current directory.

    $ php composer.phar require

After adding/changing the requirements, the modified requirements will be
installed or updated.

If you do not want to choose requirements interactively, you can just pass them
to the command.

    $ php composer.phar require vendor/package:2.* vendor/package2:dev-master

### Options

* **--prefer-source:** Install packages from `source` when available.
* **--dev:** Add packages to `require-dev`.

## search

The search command allows you to search through the current project's package
repositories. Usually this will be just packagist. You simply pass it the
terms you want to search for.

    $ php composer.phar search monolog

You can also search for more than one term by passing multiple arguments.

## show

To list all of the available packages, you can use the `show` command.

    $ php composer.phar show

If you want to see the details of a certain package, you can pass the package
name.

    $ php composer.phar show monolog/monolog

    name     : monolog/monolog
    versions : master-dev, 1.0.2, 1.0.1, 1.0.0, 1.0.0-RC1
    type     : library
    names    : monolog/monolog
    source   : [git] http://github.com/Seldaek/monolog.git 3d4e60d0cbc4b888fe5ad223d77964428b1978da
    dist     : [zip] http://github.com/Seldaek/monolog/zipball/3d4e60d0cbc4b888fe5ad223d77964428b1978da 3d4e60d0cbc4b888fe5ad223d77964428b1978da
    license  : MIT

    autoload
    psr-0
    Monolog : src/

    requires
    php >=5.3.0

You can even pass the package version, which will tell you the details of that
specific version.

    $ php composer.phar show monolog/monolog 1.0.2

### Options

* **--installed:** Will list the packages that are installed.
* **--platform:** Will list only platform packages (php & extensions).
* **--dev:** Will include dev-required packages when combined with **--installed** or **--platform**.

## depends

The `depends` command tells you which other packages depend on a certain
package. You can specify which link types (`require`, `require-dev`)
should be included in the listing. By default both are used.

    $ php composer.phar depends --link-type=require monolog/monolog

    nrk/monolog-fluent
    poc/poc
    propel/propel
    symfony/monolog-bridge
    symfony/symfony

### Options

* **--link-type:** The link types to match on, can be specified multiple
  times.

## validate

You should always run the `validate` command before you commit your
`composer.json` file, and before you tag a release. It will check if your
`composer.json` is valid.

    $ php composer.phar validate

## self-update

To update composer itself to the latest version, just run the `self-update`
command. It will replace your `composer.phar` with the latest version.

    $ php composer.phar self-update

If you have installed composer for your entire system (see [global installation](00-intro.md#globally)),
you have to run the command with `root` privileges

    $ sudo composer self-update

## create-project

You can use Composer to create new projects from an existing package.
There are several applications for this:

1. You can deploy application packages.
2. You can check out any package and start developing on patches for example.
3. Projects with multiple developers can use this feature to bootstrap the
   initial application for development.

To create a new project using composer you can use the "create-project" command.
Pass it a package name, and the directory to create the project in. You can also
provide a version as third argument, otherwise the latest version is used.

The directory is not allowed to exist, it will be created during installation.

    php composer.phar create-project doctrine/orm path 2.2.0

By default the command checks for the packages on packagist.org.

### Options

* **--repository-url:** Provide a custom repository to search for the package,
  which will be used instead of packagist. Can be either an HTTP URL pointing
  to a `composer` repository, or a path to a local `packages.json` file.
* **--prefer-source:** Get a development version of the code checked out
  from version control.
* **--dev:** Install packages listed in `require-dev`.

## dump-autoload

If you need to update the autoloader because of new classes in a classmap
package for example, you can use "dump-autoload" to do that without having to
go through an install or update.

Additionally, it can dump an optimized autoloader that converts PSR-0 packages
into classmap ones for performance reasons. In large applications with many
classes, the autoloader can take up a substantial portion of every request's
time. Using classmaps for everything is less convenient in development, but
using this option you can still use PSR-0 for convenience and classmaps for
performance.

### Options

* **--optimize:** Convert PSR-0 autoloading to classmap to get a faster
  autoloader. This is recommended especially for production, but can take
  a bit of time to run so it is currently not done by default.

## help

To get more information about a certain command, just use `help`.

    $ php composer.phar help install

## Environment variables

You can set a number of environment variables that override certain settings.
Whenever possible it is recommended to specify these settings in the `config`
section of `composer.json` instead. It is worth noting that that the env vars
will always take precedence over the values specified in `composer.json`.

### COMPOSER

By setting the `COMPOSER` env variable it is possible to set the filename of
`composer.json` to something else.

For example:

    $ COMPOSER=composer-other.json php composer.phar install

### COMPOSER_ROOT_VERSION

By setting this var you can specify the version of the root package, if it can
not be guessed from VCS info and is not present in `composer.json`.

### COMPOSER_VENDOR_DIR

By setting this var you can make composer install the dependencies into a
directory other than `vendor`.

### COMPOSER_BIN_DIR

By setting this option you can change the `bin` ([Vendor Bins](articles/vendor-bins.md))
directory to something other than `vendor/bin`.

### http_proxy or HTTP_PROXY

If you are using composer from behind an HTTP proxy, you can use the standard
`http_proxy` or `HTTP_PROXY` env vars. Simply set it to the URL of your proxy.
Many operating systems already set this variable for you.

Using `http_proxy` (lowercased) or even defining both might be preferable since
some tools like git or curl will only use the lower-cased `http_proxy` version.
Alternatively you can also define the git proxy using
`git config --global http.proxy <proxy url>`.

### COMPOSER_HOME

The `COMPOSER_HOME` var allows you to change the composer home directory. This
is a hidden, global (per-user on the machine) directory that is shared between
all projects.

By default it points to `/home/<user>/.composer` on *nix,
`/Users/<user>/.composer` on OSX and
`C:\Users\<user>\AppData\Roaming\Composer` on Windows.

#### COMPOSER_HOME/config.json

You may put a `config.json` file into the location which `COMPOSER_HOME` points
to. Composer will merge this configuration with your project's `composer.json`
when you run the `install` and `update` commands.

This file allows you to set [configuration](04-schema.md#config) and
[repositories](05-repositories.md) for the user's projects.

In case global configuration matches _local_ configuration, the _local_
configuration in the project's `composer.json` always wins.

### COMPOSER_PROCESS_TIMEOUT

This env var controls the time composer waits for commands (such as git
commands) to finish executing. The default value is 300 seconds (5 minutes).

&larr; [Libraries](02-libraries.md)  |  [Schema](04-schema.md) &rarr;
