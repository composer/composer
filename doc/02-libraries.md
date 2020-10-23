# Libraries

This chapter will tell you how to make your library installable through
Composer.

## Every project is a package

As soon as you have a `composer.json` in a directory, that directory is a
package. When you add a [`require`](04-schema.md#require) to a project, you are
making a package that depends on other packages. The only difference between
your project and a library is that your project is a package without a name.

In order to make that package installable you need to give it a name. You do
this by adding the [`name`](04-schema.md#name) property in `composer.json`:

```json
{
    "name": "acme/hello-world",
    "require": {
        "monolog/monolog": "1.0.*"
    }
}
```

In this case the project name is `acme/hello-world`, where `acme` is the vendor
name. Supplying a vendor name is mandatory.

> **Note:** If you don't know what to use as a vendor name, your GitHub
> username is usually a good bet. While package names are case insensitive, the
> convention is all lowercase and dashes for word separation.

## Library Versioning

In the vast majority of cases, you will be maintaining your library using some
sort of version control system like git, svn, hg or fossil. In these cases,
Composer infers versions from your VCS and you **should not** specify a version
in your `composer.json` file. (See the [Versions article](articles/versions.md)
to learn about how Composer uses VCS branches and tags to resolve version
constraints.)

If you are maintaining packages by hand (i.e., without a VCS), you'll need to
specify the version explicitly by adding a `version` value in your `composer.json`
file:

```json
{
    "version": "1.0.0"
}
```

> **Note:** When you add a hardcoded version to a VCS, the version will conflict
> with tag names. Composer will not be able to determine the version number.

### VCS Versioning

Composer uses your VCS's branch and tag features to resolve the version
constraints you specify in your [`require`](04-schema.md#require) field to specific sets of files.
When determining valid available versions, Composer looks at all of your tags
and branches and translates their names into an internal list of options that
it then matches against the version constraint you provided.

For more on how Composer treats tags and branches and how it resolves package
version constraints, read the [versions](articles/versions.md) article.

## Lock file

For your library you may commit the `composer.lock` file if you want to. This
can help your team to always test against the same dependency versions.
However, this lock file will not have any effect on other projects that depend
on it. It only has an effect on the main project.

If you do not want to commit the lock file and you are using git, add it to
the `.gitignore`.

## Publishing to a VCS

Once you have a VCS repository (version control system, e.g. git) containing a
`composer.json` file, your library is already composer-installable. In this
example we will publish the `acme/hello-world` library on GitHub under
`github.com/username/hello-world`.

Now, to test installing the `acme/hello-world` package, we create a new
project locally. We will call it `acme/blog`. This blog will depend on
`acme/hello-world`, which in turn depends on `monolog/monolog`. We can
accomplish this by creating a new `blog` directory somewhere, containing a
`composer.json`:

```json
{
    "name": "acme/blog",
    "require": {
        "acme/hello-world": "dev-master"
    }
}
```

The name is not needed in this case, since we don't want to publish the blog
as a library. It is added here to clarify which `composer.json` is being
described.

Now we need to tell the blog app where to find the `hello-world` dependency.
We do this by adding a package repository specification to the blog's
`composer.json`:

```json
{
    "name": "acme/blog",
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/username/hello-world"
        }
    ],
    "require": {
        "acme/hello-world": "dev-master"
    }
}
```

For more details on how package repositories work and what other types are
available, see [Repositories](05-repositories.md).

That's all. You can now install the dependencies by running Composer's
[`install`](03-cli.md#install) command!

**Recap:** Any git/svn/hg/fossil repository containing a `composer.json` can be
added to your project by specifying the package repository and declaring the
dependency in the [`require`](04-schema.md#require) field.

## Publishing to packagist

Alright, so now you can publish packages. But specifying the VCS repository
every time is cumbersome. You don't want to force all your users to do that.

The other thing that you may have noticed is that we did not specify a package
repository for `monolog/monolog`. How did that work? The answer is Packagist.

[Packagist](https://packagist.org/) is the main package repository for
Composer, and it is enabled by default. Anything that is published on
Packagist is available automatically through Composer. Since
[Monolog is on Packagist](https://packagist.org/packages/monolog/monolog), we
can depend on it without having to specify any additional repositories.

If we wanted to share `hello-world` with the world, we would publish it on
Packagist as well.

You visit [Packagist](https://packagist.org) and hit the "Submit"
button. This will prompt you to sign up if you haven't already, and then
allows you to submit the URL to your VCS repository, at which point Packagist
will start crawling it. Once it is done, your package will be available to
anyone!

&larr; [Basic usage](01-basic-usage.md) |  [Command-line interface](03-cli.md) &rarr;
