# Introduction

Composer is a tool for dependency management in PHP. It allows you to declare
the dependent libraries your project needs and it will install them in your
project for you.

## Dependency management

Composer is not a package manager. Yes, it deals with "packages" or libraries, but
it manages them on a per-project basis, installing them in a directory (e.g. `vendor`)
inside your project. By default it will never install anything globally. Thus,
it is a dependency manager.

This idea is not new and Composer is strongly inspired by node's [npm](http://npmjs.org/)
and ruby's [bundler](http://gembundler.com/). But there has not been such a tool
for PHP.

The problem that Composer solves is this:

a) You have a project that depends on a number of libraries.

b) Some of those libraries depend on other libraries.

c) You declare the things you depend on.

d) Composer finds out which versions of which packages need to be installed, and
   installs them (meaning it downloads them into your project).

## Declaring dependencies

Let's say you are creating a project, and you need a library that does logging.
You decide to use [monolog](https://github.com/Seldaek/monolog). In order to
add it to your project, all you need to do is create a `composer.json` file
which describes the project's dependencies.

```json
{
    "require": {
        "monolog/monolog": "1.2.*"
    }
}
```

We are simply stating that our project requires some `monolog/monolog` package,
any version beginning with `1.2`.

## System Requirements

Composer requires PHP 5.3.2+ to run. A few sensitive php settings and compile
flags are also required, but when using the installer you will be warned about any
incompatibilities.

To install packages from sources instead of simple zip archives, you will need
git, svn or hg depending on how the package is version-controlled.

Composer is multi-platform and we strive to make it run equally well on Windows,
Linux and OSX.

## Installation - Linux / Unix / OSX

### Downloading the Composer Executable

There are in short, two ways to install Composer. Locally as part of your
project, or globally as a system wide executable.

#### Locally

Installing Composer locally is a matter of just running the installer in your
project directory:

```sh
curl -sS https://getcomposer.org/installer | php
```

> **Note:** If the above fails for some reason, you can download the installer
> with `php` instead:

```sh
php -r "readfile('https://getcomposer.org/installer');" | php
```

The installer will just check a few PHP settings and then download `composer.phar`
to your working directory. This file is the Composer binary. It is a PHAR (PHP
archive), which is an archive format for PHP which can be run on the command
line, amongst other things.

You can install Composer to a specific directory by using the `--install-dir`
option and providing a target directory (it can be an absolute or relative path):

```sh
curl -sS https://getcomposer.org/installer | php -- --install-dir=bin
```

#### Globally

You can place this file anywhere you wish. If you put it in your `PATH`,
you can access it globally. On unixy systems you can even make it
executable and invoke it without `php`.

You can run these commands to easily access `composer` from anywhere on your system:

```sh
curl -sS https://getcomposer.org/installer | php
mv composer.phar /usr/local/bin/composer
```

> **Note:** If the above fails due to permissions, run the `mv` line
> again with sudo.

> **Note:** In OSX Yosemite the `/usr` directory does not exist by default. If you receive the error "/usr/local/bin/composer: No such file or directory" then you must create `/usr/local/bin/` manually before proceeding.

Then, just run `composer` in order to run Composer instead of `php composer.phar`.

## Installation - Windows

### Using the Installer

This is the easiest way to get Composer set up on your machine.

Download and run [Composer-Setup.exe](https://getcomposer.org/Composer-Setup.exe),
it will install the latest Composer version and set up your PATH so that you can
just call `composer` from any directory in your command line.

> **Note:** Close your current terminal. Test usage with a new terminal:
> That is important since the PATH only gets loaded when the terminal starts.

### Manual Installation

Change to a directory on your `PATH` and run the install snippet to download
composer.phar:

```sh
C:\Users\username>cd C:\bin
C:\bin>php -r "readfile('https://getcomposer.org/installer');" | php
```

> **Note:** If the above fails due to readfile, use the `http` url or enable php_openssl.dll in php.ini

Create a new `composer.bat` file alongside `composer.phar`:

```sh
C:\bin>echo @php "%~dp0composer.phar" %*>composer.bat
```

Close your current terminal. Test usage with a new terminal:

```sh
C:\Users\username>composer -V
Composer version 27d8904
```

## Using Composer

We will now use Composer to install the dependencies of the project. If you
don't have a `composer.json` file in the current directory please skip to the
[Basic Usage](01-basic-usage.md) chapter.

To resolve and download dependencies, run the `install` command:

```sh
php composer.phar install
```

If you did a global install and do not have the phar in that directory
run this instead:

```sh
composer install
```

Following the [example above](#declaring-dependencies), this will download
monolog into the `vendor/monolog/monolog` directory.

## Autoloading

Besides downloading the library, Composer also prepares an autoload file that's
capable of autoloading all of the classes in any of the libraries that it
downloads. To use it, just add the following line to your code's bootstrap
process:

```php
require 'vendor/autoload.php';
```

Woah! Now start using monolog! To keep learning more about Composer, keep
reading the "Basic Usage" chapter.

[Basic Usage](01-basic-usage.md) &rarr;
