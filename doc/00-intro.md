# Introduction

Composer is a tool for dependency management in PHP. It allows you to declare
the dependencies of your project and will install them for you.

## Dependency management

One important distinction to make is that composer is not a package manager. It
deals with packages, but it manages them on a per-project basis. By default it
will never install anything globally. Thus, it is a dependency manager.

This idea is not new by any means. Composer is strongly inspired by
node's [npm](http://npmjs.org/) and ruby's [bundler](http://gembundler.com/).
But there has not been such a tool for PHP so far.

The problem that composer solves is the following. You have a project that
depends on a number of libraries. Some of those libraries have dependencies of
their own. You declare the things you depend on. Composer will then go ahead
and find out which versions of which packages need to be installed, and
install them.

## Declaring dependencies

Let's say you are creating a project, and you need a library that does logging.
You decide to use [monolog](https://github.com/Seldaek/monolog). In order to
add it to your project, all you need to do is create a `composer.json` file
which describes the project's dependencies.

```json
{
    "require": {
        "monolog/monolog": "1.0.*"
    }
}
```

We are simply stating that our project requires the `monolog/monolog` package,
any version beginning with `1.0`.

## Installation

To actually get it, we need to do two things. The first one is installing
composer:

    $ curl -s http://getcomposer.org/installer | php

This will just check a few PHP settings and then download `composer.phar` to
your working directory. This file is the composer binary.

After that we run the command for installing all dependencies:

    $ php composer.phar install

This will download monolog and dump it into `vendor/monolog/monolog`.

## Autoloading

After this you can just add the following line to your bootstrap code to get
autoloading:

```php
require 'vendor/.composer/autoload.php';
```

That's all it takes to have a basic setup.
