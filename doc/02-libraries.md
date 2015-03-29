# Libraries

This chapter will tell you how to make your library installable through Composer.

## Every project is a package

As soon as you have a `composer.json` in a directory, that directory is a
package. When you add a `require` to a project, you are making a package that
depends on other packages. The only difference between your project and
libraries is that your project is a package without a name.

In order to make that package installable you need to give it a name. You do
this by adding a `name` to `composer.json`:

```json
{
    "name": "acme/hello-world",
    "require": {
        "monolog/monolog": "1.0.*"
    }
}
```

In this case the project name is `acme/hello-world`, where `acme` is the
vendor name. Supplying a vendor name is mandatory.

> **Note:** If you don't know what to use as a vendor name, your GitHub
username is usually a good bet. While package names are case insensitive, the
convention is all lowercase and dashes for word separation.

## Platform packages

Composer has platform packages, which are virtual packages for things that are
installed on the system but are not actually installable by Composer. This
includes PHP itself, PHP extensions and some system libraries.

* `php` represents the PHP version of the user, allowing you to apply
  constraints, e.g. `>=5.4.0`. To require a 64bit version of php, you can
  require the `php-64bit` package.

* `hhvm` represents the version of the HHVM runtime (aka HipHop Virtual
  Machine) and allows you to apply a constraint, e.g., '>=2.3.3'.

* `ext-<name>` allows you to require PHP extensions (includes core
  extensions). Versioning can be quite inconsistent here, so it's often
  a good idea to just set the constraint to `*`.  An example of an extension
  package name is `ext-gd`.

* `lib-<name>` allows constraints to be made on versions of libraries used by
  PHP. The following are available: `curl`, `iconv`, `icu`, `libxml`,
  `openssl`, `pcre`, `uuid`, `xsl`.

You can use `composer show --platform` to get a list of your locally available
platform packages.

## Specifying the version

You need to specify the package's version some way. When you publish your
package on Packagist, it is able to infer the version from the VCS (git, svn,
hg) information, so in that case you do not have to specify it, and it is
recommended not to. See [tags](#tags) and [branches](#branches) to see how
version numbers are extracted from these.

If you are creating packages by hand and really have to specify it explicitly,
you can just add a `version` field:

```json
{
    "version": "1.0.0"
}
```

> **Note:** You should avoid specifying the version field explicitly, because
> for tags the value must match the tag name.

### Tags

For every tag that looks like a version, a package version of that tag will be
created. It should match 'X.Y.Z' or 'vX.Y.Z', with an optional suffix
of `-patch` (`-p`), `-alpha` (`-a`), `-beta` (`-b`) or `-RC`. The suffixes
can also be followed by a number.

Here are a few examples of valid tag names:

- 1.0.0
- v1.0.0
- 1.10.5-RC1
- v4.4.4-beta2
- v2.0.0-alpha
- v2.0.4-p1

> **Note:** Even if your tag is prefixed with `v`, a [version constraint](01-basic-usage.md#package-versions)
> in a `require` statement has to be specified without prefix
> (e.g. tag `v1.0.0` will result in version `1.0.0`). 

### Branches

For every branch, a package development version will be created. If the branch
name looks like a version, the version will be `{branchname}-dev`. For example
a branch `2.0` will get a version `2.0.x-dev` (the `.x` is added for technical
reasons, to make sure it is recognized as a branch, a `2.0.x` branch would also
be valid and be turned into `2.0.x-dev` as well. If the branch does not look
like a version, it will be `dev-{branchname}`. `master` results in a
`dev-master` version.

Here are some examples of version branch names:

- 1.x
- 1.0 (equals 1.0.x)
- 1.1.x

> **Note:** When you install a development version, it will be automatically
> pulled from its `source`. See the [`install`](03-cli.md#install) command
> for more details.

### Aliases

It is possible to alias branch names to versions. For example, you could alias
`dev-master` to `1.0.x-dev`, which would allow you to require `1.0.x-dev` in all
the packages.

See [Aliases](articles/aliases.md) for more information.

## Lock file

For your library you may commit the `composer.lock` file if you want to. This
can help your team to always test against the same dependency versions.
However, this lock file will not have any effect on other projects that depend
on it. It only has an effect on the main project.

If you do not want to commit the lock file and you are using git, add it to
the `.gitignore`.

## Publishing to a VCS

Once you have a vcs repository (version control system, e.g. git) containing a
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
`install` command!

**Recap:** Any git/svn/hg repository containing a `composer.json` can be added
to your project by specifying the package repository and declaring the
dependency in the `require` field.

## Publishing to packagist

Alright, so now you can publish packages. But specifying the vcs repository
every time is cumbersome. You don't want to force all your users to do that.

The other thing that you may have noticed is that we did not specify a package
repository for `monolog/monolog`. How did that work? The answer is packagist.

[Packagist](https://packagist.org/) is the main package repository for
Composer, and it is enabled by default. Anything that is published on
packagist is available automatically through Composer. Since monolog
[is on packagist](https://packagist.org/packages/monolog/monolog), we can depend
on it without having to specify any additional repositories.

If we wanted to share `hello-world` with the world, we would publish it on
packagist as well. Doing so is really easy.

You simply hit the big "Submit Package" button and sign up. Then you submit
the URL to your VCS repository, at which point packagist will start crawling
it. Once it is done, your package will be available to anyone.

&larr; [Basic usage](01-basic-usage.md) |  [Command-line interface](03-cli.md) &rarr;
