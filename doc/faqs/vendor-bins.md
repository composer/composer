# bin and vendor/bin

## What is a bin?

Any runnable code that a Composer package would like to pass along
to a user who installs the package should be listed as a bin.

If a package contains other runnable code that is not needed by the
user (like build or compile scripts) that code should not be listed
as a bin.


## How is it defined?

It is defined by adding the `bin` key to a project's `composer.json`.
It is specified as an array of files so multiple bins can be added
for any given project.

```json
{
    "bin": ["bin/my-script", "bin/my-other-script"]
}
```


## What does defining a bin in composer.json do?

It instructs Composer to install the package's bins to `vendor/bin`
for any project that **depends** on that project.

This is a convenient way to expose useful runnable code that would
otherwise be hidden deep in the `vendor/` directory.


## What happens when Composer is run on a composer.json that defines bins?

For the bins that a package defines directly, nothing happens.


## What happens when Composer is run on a composer.json that has dependencies with bins listed?

Composer looks for the bins defined in all of the dependencies. A
symlink is created from each dependency's bins to `vendor/bin`.

Say package `my-vendor/project-a` has bins setup like this:

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
    "requires": {
        "my-vendor/project-a": "*"
    }
}
```

Running `composer install` for this `composer.json` will look at
all of project-b's dependencies and install them to `vendor/bin`.

In this case, Composer will create a symlink from
`vendor/my-vendor/project-a/bin/project-a-bin` to `vendor/bin/project-a-bin`.


## What about Windows and .bat files?

Composer will automatically create `.bat` files for bins installed in a
Windows environment. Package maintainers should not list self managed
`.bat` files as bins.