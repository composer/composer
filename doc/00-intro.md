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

b) Some of those libraries depend on other libraries .

c) You declare the things you depend on

d) Composer finds out which versions of which packages need to be installed, and
   installs them (meaning it downloads them into your project).

## Declaring dependencies

Let's say you are creating a project, and you need a library that does logging.
You decide to use [monolog](https://github.com/Seldaek/monolog). In order to
add it to your project, all you need to do is create a `composer.json` file
which describes the project's dependencies.

    {
        "require": {
            "monolog/monolog": "1.2.*"
        }
    }

We are simply stating that our project requires some `monolog/monolog` package,
any version beginning with `1.2`.

## Installation

### Downloading the Composer Executable

#### Locally

To actually get Composer, we need to do two things. The first one is installing
Composer (again, this means downloading it into your project):

    $ curl -s https://getcomposer.org/installer | php

This will just check a few PHP settings and then download `composer.phar` to
your working directory. This file is the Composer binary. It is a PHAR (PHP
archive), which is an archive format for PHP which can be run on the command
line, amongst other things.

You can install Composer to a specific directory by using the `--install-dir`
option and providing a target directory (it can be an absolute or relative path):

    $ curl -s https://getcomposer.org/installer | php -- --install-dir=bin

#### Globally

You can place this file anywhere you wish. If you put it in your `PATH`,
you can access it globally. On unixy systems you can even make it
executable and invoke it without `php`.

You can run these commands to easily access `composer` from anywhere on your system:

    $ curl -s https://getcomposer.org/installer | php
    $ sudo mv composer.phar /usr/local/bin/composer

Then, just run `composer` in order to run composer

### Using Composer

Next, run the `install` command to resolve and download dependencies:

    $ php composer.phar install

This will download monolog into the `vendor/monolog/monolog` directory.

## Autoloading

Besides downloading the library, Composer also prepares an autoload file that's
capable of autoloading all of the classes in any of the libraries that it
downloads. To use it, just add the following line to your code's bootstrap
process:

    require 'vendor/autoload.php';

Woh! Now start using monolog! To keep learning more about Composer, keep
reading the "Basic Usage" chapter.

[Basic Usage](01-basic-usage.md) &rarr;
