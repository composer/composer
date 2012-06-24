# Repositories

This chapter will explain the concept of packages and repositories, what kinds
of repositories are available, and how they work.

## Concepts

Before we look at the different types of repositories that exist, we need to
understand some of the basic concepts that composer is built on.

### Package

Composer is a dependency manager. It installs packages locally. A package is
essentially just a directory containing something. In this case it is PHP
code, but in theory it could be anything. And it contains a package
description which has a name and a version. The name and the version are used
to identify the package.

In fact, internally composer sees every version as a separate package. While
this distinction does not matter when you are using composer, it's quite
important when you want to change it.

In addition to the name and the version, there is useful metadata. The information
most relevant for installation is the source definition, which describes where
to get the package contents. The package data points to the contents of the
package. And there are two options here: dist and source.

**Dist:** The dist is a packaged version of the package data. Usually a
released version, usually a stable release.

**Source:** The source is used for development. This will usually originate
from a source code repository, such as git. You can fetch this when you want
to modify the downloaded package.

Packages can supply either of these, or even both. Depending on certain
factors, such as user-supplied options and stability of the package, one will
be preferred.

### Repository

A repository is a package source. It's a list of packages/versions. Composer
will look in all your repositories to find the packages your project requires.

By default only the Packagist repository is registered in Composer. You can
add more repositories to your project by declaring them in `composer.json`.

Repositories are only available to the root package and the repositories
defined in your dependencies will not be loaded. Read the
[FAQ entry](faqs/why-can't-composer-load-repositories-recursively.md) if you
want to learn why.

## Types

### Composer

The main repository type is the `composer` repository. It uses a single
`packages.json` file that contains all of the package metadata.

This is also the repository type that packagist uses. To reference a
`composer` repository, just supply the path before the `packages.json` file.
In case of packagist, that file is located at `/packages.json`, so the URL of
the repository would be `packagist.org`. For `example.org/packages.json` the
repository URL would be `example.org`.

#### packages

The only required field is `packages`. The JSON structure is as follows:

    {
        "packages": {
            "vendor/packageName": {
                "dev-master": { @composer.json },
                "1.0.x-dev": { @composer.json },
                "0.0.1": { @composer.json },
                "1.0.0": { @composer.json }
            }
        }
    }

The `@composer.json` marker would be the contents of the `composer.json` from
that package version including as a minimum:

* name
* version
* dist or source

Here is a minimal package definition:

    {
        "name": "smarty/smarty",
        "version": "3.1.7",
        "dist": {
            "url": "http://www.smarty.net/files/Smarty-3.1.7.zip",
            "type": "zip"
        }
    }

It may include any of the other fields specified in the [schema](04-schema.md).

#### notify

The `notify` field allows you to specify an URL template for a URL that will
be called every time a user installs a package.

An example value:

    {
        "notify": "/downloads/%package%"
    }

For `example.org/packages.json` containing a `monolog/monolog` package, this
would send a `POST` request to `example.org/downloads/monolog/monolog` with
following parameters:

* **version:** The version of the package.
* **version_normalized:** The normalized internal representation of the
  version.

This field is optional.

#### includes

For large repositories it is possible to split the `packages.json` into
multiple files. The `includes` field allows you to reference these additional
files.

An example:

    {
        "includes": {
            "packages-2011.json": {
                "sha1": "525a85fb37edd1ad71040d429928c2c0edec9d17"
            },
            "packages-2012-01.json": {
                "sha1": "897cde726f8a3918faf27c803b336da223d400dd"
            },
            "packages-2012-02.json": {
                "sha1": "26f911ad717da26bbcac3f8f435280d13917efa5"
            }
        }
    }

The SHA-1 sum of the file allows it to be cached and only re-requested if the
hash changed.

This field is optional. You probably don't need it for your own custom
repository.

### VCS

VCS stands for version control system. This includes versioning systems like
git, svn or hg. Composer has a repository type for installing packages from
these systems.

There are a few use cases for this. The most common one is maintaining your
own fork of a third party library. If you are using a certain library for your
project and you decide to change something in the library, you will want your
project to use the patched version. If the library is on GitHub (this is the
case most of the time), you can simply fork it there and push your changes to
your fork. After that you update the project's `composer.json`. All you have
to do is add your fork as a repository and update the version constraint to
point to your custom branch.

Example assuming you patched monolog to fix a bug in the `bugfix` branch:

    {
        "repositories": [
            {
                "type": "vcs",
                "url": "http://github.com/igorw/monolog"
            }
        ],
        "require": {
            "monolog/monolog": "dev-bugfix"
        }
    }

When you run `php composer.phar update`, you should get your modified version
of `monolog/monolog` instead of the one from packagist.

Git is not the only version control system supported by the VCS repository.
The following are supported:

* **Git:** [git-scm.com](http://git-scm.com)
* **Subversion:** [subversion.apache.org](http://subversion.apache.org)
* **Mercurial:** [mercurial.selenic.com](http://mercurial.selenic.com)

To get packages from these systems you need to have their respective clients
installed. That can be inconvenient. And for this reason there is special
support for GitHub and BitBucket that use the APIs provided by these sites, to
fetch the packages without having to install the version control system. The
VCS repository provides `dist`s for them that fetch the packages as zips.

* **GitHub:** [github.com](https://github.com) (Git)
* **BitBucket:** [bitbucket.org](https://bitbucket.org) (Git and Mercurial)

The VCS driver to be used is detected automatically based on the URL. However,
should you need to specify one for whatever reason, you can use `git`, `svn` or
`hg` as the repository type instead of `vcs`.

### PEAR

It is possible to install packages from any PEAR channel by using the `pear`
repository. Composer will prefix all package names with `pear-{channelName}/` to
avoid conflicts. All packages are also aliased with prefix `pear-{channelAlias}/`

Example using `pear2.php.net`:

    {
        "repositories": [
            {
                "type": "pear",
                "url": "http://pear2.php.net"
            }
        ],
        "require": {
            "pear-pear2.php.net/PEAR2_Text_Markdown": "*",
            "pear-pear2/PEAR2_HTTP_Request": "*"
        }
    }

In this case the short name of the channel is `pear2`, so the
`PEAR2_HTTP_Request` package name becomes `pear-pear2/PEAR2_HTTP_Request`.

> **Note:** The `pear` repository requires doing quite a few requests per
> package, so this may considerably slow down the installation process.

#### Custom channel alias
It is possible to alias all pear channel packages with custom name.

Example:
 You own private pear repository and going to use composer abilities to bring
 dependencies from vcs or transit to composer repository scheme.
 List of packages:
 * BasePackage, requires nothing
 * IntermediatePackage, depends on BasePackage
 * TopLevelPackage1 and TopLevelPackage2 both dependth on IntermediatePackage.

 For composer it looks like:
 * "pear-pear.foobar.repo/IntermediatePackage" depends on "pear-pear.foobar.repo/BasePackage",
 * "pear-pear.foobar.repo/TopLevelPackage1" depends on "pear-pear.foobar.repo/IntermediatePackage",
 * "pear-pear.foobar.repo/TopLevelPackage2" depends on "pear-pear.foobar.repo/IntermediatePackage"
 When you update one of your packages to composer naming scheme or made it
 available through vcs your older dependencies would not see new version cause it would be named
 like "foobar/IntermediatePackage".

 Specifying 'vendor-alias' for pear repository you will get all its packages aliased with composer-like names.
 Following example would take BasePackage, TopLevelPackage1 and TopLevelPackage2 packages from pear repository
 and IntermediatePackage from github repository:
    {
        "repositories": [
            {
                "type": "git",
                "https://github.com/foobar/intermediate.git"
            },
            {
                "type": "pear",
                "url": "http://pear.foobar.repo",
                "vendor-alias": "foobar"
            }
        ],
        "require": {
            "foobar/TopLevelPackage1": "*",
            "foobar/TopLevelPackage2": "*"
        }
    }


### Package

If you want to use a project that does not support composer through any of the
means above, you still can define the package yourself by using a `package`
repository.

Basically, you define the same information that is included in the `composer`
repository's `packages.json`, but only for a single package. Again, the
minimum required fields are `name`, `version`, and either of `dist` or
`source`.

Here is an example for the smarty template engine:

    {
        "repositories": [
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
                    },
                    "autoload": {
                        "classmap": ["libs/"]
                    }
                }
            }
        ],
        "require": {
            "smarty/smarty": "3.1.*"
        }
    }

Typically you would leave the source part off, as you don't really need it.

## Hosting your own

While you will probably want to put your packages on packagist most of the time,
there are some use cases for hosting your own repository.

* **Private company packages:** If you are part of a company that uses composer
  for their packages internally, you might want to keep those packages private.

* **Separate ecosystem:** If you have a project which has its own ecosystem,
  and the packages aren't really reusable by the greater PHP community, you
  might want to keep them separate to packagist. An example of this would be
  wordpress plugins.

When hosting your own package repository it is recommended to use a `composer`
one. This is type that is native to composer and yields the best performance.

There are a few tools that can help you create a `composer` repository.

### Packagist

The underlying application used by packagist is open source. This means that you
can just install your own copy of packagist, re-brand, and use it. It's really
quite straight-forward to do. However due to its size and complexity, for most
small and medium sized companies willing to track a few packages will be better
off using Satis.

Packagist is a Symfony2 application, and it is [available on
GitHub](https://github.com/composer/packagist). It uses composer internally and
acts as a proxy between VCS repositories and the composer users. It holds a list
of all VCS packages, periodically re-crawls them, and exposes them as a composer
repository.

To set your own copy, simply follow the instructions from the [packagist
github repository](https://github.com/composer/packagist).

### Satis

Satis is a static `composer` repository generator. It is a bit like an ultra-
lightweight, static file-based version of packagist.

You give it a `composer.json` containing repositories, typically VCS and
package repository definitions. It will fetch all the packages that are
`require`d and dump a `packages.json` that is your `composer` repository.

Check [the satis GitHub repository](https://github.com/composer/satis) and
the [Satis article](articles/handling-private-packages-with-satis.md) for more
information.

## Disabling Packagist

You can disable the default Packagist repository by adding this to your
`composer.json`:

    {
        "repositories": [
            {
                "packagist": false
            }
        ]
    }


&larr; [Schema](04-schema.md)  |  [Community](06-community.md) &rarr;
