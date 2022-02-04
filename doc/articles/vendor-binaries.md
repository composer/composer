<!--
    tagline: Expose command-line scripts from packages
-->

# Vendor binaries and the `vendor/bin` directory

## What is a vendor binary?

Any command line script that a Composer package would like to pass along
to a user who installs the package should be listed as a vendor binary.

If a package contains other scripts that are not needed by the package
users (like build or compile scripts) that code should not be listed
as a vendor binary.

## How is it defined?

It is defined by adding the `bin` key to a project's `composer.json`.
It is specified as an array of files so multiple binaries can be added
for any given project.

```json
{
    "bin": ["bin/my-script", "bin/my-other-script"]
}
```

## What does defining a vendor binary in composer.json do?

It instructs Composer to install the package's binaries to `vendor/bin`
for any project that **depends** on that project.

This is a convenient way to expose useful scripts that would
otherwise be hidden deep in the `vendor/` directory.

## What happens when Composer is run on a composer.json that defines vendor binaries?

For the binaries that a package defines directly, nothing happens.

## What happens when Composer is run on a composer.json that has dependencies with vendor binaries listed?

Composer looks for the binaries defined in all of the dependencies. A
proxy file (or two on Windows/WSL) is created from each dependency's
binaries to `vendor/bin`.

Say package `my-vendor/project-a` has binaries setup like this:

```json
{
    "name": "my-vendor/project-a",
    "bin": ["bin/project-a-bin"]
}
```

Running `composer install` for this `composer.json` will not do
anything with `bin/project-a-bin`.

Say project `my-vendor/project-b` has requirements setup like this:

```json
{
    "name": "my-vendor/project-b",
    "require": {
        "my-vendor/project-a": "*"
    }
}
```

Running `composer install` for this `composer.json` will look at
all of project-a's binaries and install them to `vendor/bin`.

In this case, Composer will make `vendor/my-vendor/project-a/bin/project-a-bin`
available as `vendor/bin/project-a-bin`.

## Finding the Composer autoloader from a binary

As of Composer 2.2, a new `$_composer_autoload_path` global variable
is defined by the bin proxy file, so that when your binary gets executed
it can use it to easily locate the project's autoloader.

This global will not be available however when running binaries defined
by the root package itself, so you need to have a fallback in place.

This can look like this for example:

```php
<?php

include $_composer_autoload_path ?? __DIR__ . '/../vendor/autoload.php';
```

If you want to rely on this in your package you should however make sure to
also require `"composer-runtime-api": "^2.2"` to ensure that the package
gets installed with a Composer version supporting the feature.

## Finding the Composer bin-dir from a binary

As of Composer 2.2.2, a new `$_composer_bin_dir` global variable
is defined by the bin proxy file, so that when your binary gets executed
it can use it to easily locate the project's autoloader.

For non-PHP binaries, as of Composer 2.2.6, the bin proxy sets a
`COMPOSER_RUNTIME_BIN_DIR` environment variable.

This global variable will not be available however when running binaries defined
by the root package itself, so you need to have a fallback in place.

This can look like this for example:

```php
<?php

$binDir = $_composer_bin_dir ?? __DIR__ . '/../vendor/bin';
```

```php
#!/bin/bash

if [[ -z "$COMPOSER_RUNTIME_BIN_DIR" ]]; then
  BIN_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
else
  BIN_DIR="$COMPOSER_RUNTIME_BIN_DIR"
fi
```

If you want to rely on this in your package you should however make sure to
also require `"composer-runtime-api": "^2.2.2"` to ensure that the package
gets installed with a Composer version supporting the feature.

## What about Windows and .bat files?

Packages managed entirely by Composer do not *need* to contain any
`.bat` files for Windows compatibility. Composer handles installation
of binaries in a special way when run in a Windows environment:

 * A `.bat` file is generated automatically to reference the binary
 * A Unix-style proxy file with the same name as the binary is also
   generated, which is useful for WSL, Linux VMs, etc.

Packages that need to support workflows that may not include Composer
are welcome to maintain custom `.bat` files. In this case, the package
should **not** list the `.bat` file as a binary as it is not needed.

## Can vendor binaries be installed somewhere other than vendor/bin?

Yes, there are two ways an alternate vendor binary location can be specified:

 1. Setting the `bin-dir` configuration setting in `composer.json`
 1. Setting the environment variable `COMPOSER_BIN_DIR`

An example of the former looks like this:

```json
{
    "config": {
        "bin-dir": "scripts"
    }
}
```

Running `composer install` for this `composer.json` will result in
all of the vendor binaries being installed in `scripts/` instead of
`vendor/bin/`.

You can set `bin-dir` to `./` to put binaries in your project root.
