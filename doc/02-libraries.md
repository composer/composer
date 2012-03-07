# Libraries

This chapter will tell you how to make your library installable through composer.

## Every project is a package

As soon as you have a `composer.json` in a directory, that directory is a
package. When you add a `require` to a project, you are making a package that
depends on other packages. The only difference between your project and
libraries is that your project is a package without a name.

In order to make that package installable you need to give it a name. You do
this by adding a `name` to `composer.json`:

    {
        "name": "acme/hello-world",
        "require": {
            "monolog/monolog": "1.0.*"
        }
    }

In this case the project name is `acme/hello-world`, where `acme` is the
vendor name. Supplying a vendor name is mandatory.

> **Note:** If you don't know what to use as a vendor name, your GitHub
username is usually a good bet. While package names are case insensitive, the
convention is all lowercase and dashes for word separation.

## Specifying the version

You need to specify the version some way. Depending on the type of repository
you are using, it might be possible to omit it from `composer.json`, because
the repository is able to infer the version from elsewhere.

If you do want to specify it explicitly, you can just add a `version` field:

    {
        "version": "1.0.0"
    }

However if you are using git, svn or hg, you don't have to specify it.
Composer will detect versions as follows:

### Tags

For every tag that looks like a version, a package version of that tag will be
created. It should match 'X.Y.Z' or 'vX.Y.Z', with an optional suffix for RC,
beta, alpha or patch.

Here are a few examples of valid tag names:

    1.0.0
    v1.0.0
    1.10.5-RC1
    v4.4.4beta2
    v2.0.0-alpha
    v2.0.4-p1

> **Note:** If you specify an explicit version in `composer.json`, the tag name must match the specified version.

### Branches

For every branch, a package development version will be created. If the branch
name looks like a version, the version will be `{branchname}-dev`. For example
a branch `2.0` will get a version `2.0-dev`. If the branch does not look like
a version, it will be `dev-{branchname}`. `master` results in a `dev-master`
version.

Here are some examples of version branch names:

    1.0
    1.*
    1.1.x
    1.1.*

> **Note:** When you install a dev version, it will install it from source.
See [Repositories](05-repositories) for more information.

## Lock file

For projects it is recommended to commit the `composer.lock` file into version
control. For libraries this is not the case. You do not want your library to
be tied to exact versions of the dependencies. It should work with any
compatible version, so make sure you specify your version constraints so that
they include all compatible versions.

**Do not commit your library's `composer.lock` into version control.**

If you are using git, add it to the `.gitignore`.

## Publishing to a VCS

Once you have a vcs repository (version control system, e.g. git) containing a
`composer.json` file, your library is already composer-installable. In this
example we will publish the `acme/hello-world` library on GitHub under
`github.com/composer/hello-world`.

Now, To test installing the `acme/hello-world` package, we create a new
project locally. We will call it `acme/blog`. This blog will depend on `acme
/hello-world`, which in turn depends on `monolog/monolog`. We can accomplish
this by creating a new `blog` directory somewhere, containing a
`composer.json`:

    {
        "name": "acme/blog",
        "require": {
            "acme/hello-world": "dev-master"
        }
    }

The name is not needed in this case, since we don't want to publish the blog
as a library. It is added here to clarify which `composer.json` is being
described.

Now we need to tell the blog app where to find the `hello-world` dependency.
We do this by adding a package repository specification to the blog's
`composer.json`:

    {
        "name": "acme/blog",
        "repositories": {
            "acme/hello-world": {
                "vcs": { "url": "https://github.com/composer/hello-world" }
            }
        },
        "require": {
            "acme/hello-world": "dev-master"
        }
    }

For more details on how package repositories work and what other types are
available, see [Repositories](05-repositories).

That's all. You can now install the dependencies by running composer's
`install` command!

**Recap:** Any git/svn/hg repository containing a `composer.json` can be added
to your project by specifying the package repository and declaring the
dependency in the `require` field.

## Publishing to packagist

Alright, so now you can publish packages. But specifying the vcs repository
every time is cumbersome. You don't want to force all your users to do that.

The other thing that you may have noticed is that we did not specify a package
repository for `monolog/monolog`. How did that work? The answer is packagist.

[Packagist](http://packagist.org/) is the main package repository for
composer, and it is enabled by default. Anything that is published on
packagist is available automatically through composer. Since monolog
[is on packagist](http://packagist.org/packages/monolog/monolog), we can depend
on it without having to specify any additional repositories.

Assuming we want to share `hello-world` with the world, we would want to
publish it on packagist as well. And this is really easy.

You simply hit the big "Submit Package" button and sign up. Then you submit
the URL to your VCS repository, at which point packagist will start crawling
it. Once it is done, your package will be available to anyone.

[prev](01-basic-usage) [next](03-cli)