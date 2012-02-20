# Repositories

This chapter will explain the concept of packages and repositories, what kinds of repositories are available, and how they work.

## Concepts

Before we look at the different types of repositories that we can have, we need to understand some of the basic concepts that composer is built on.

### Package

Composer is a dependency manager. It installs packages. A package is essentially just a directory containing something. In this case it is PHP code, but in theory it could be anything. And it contains a package description which has a name and a version. The name and the version are used to identify the package.

In fact, internally composer sees every version as a separate package. While this distinction does not matter when you are using composer, it's quite important when you want to change it.

In addition to the name and the version, there is useful data. The only really important piece of information is the package source, that describes where to get the package contents. The package data points to the contents of the package. And there are two options here: dist and source.

**Dist:** The dist is a packaged version of the package data. Usually a released version, usually a stable release.

**Source:** The source is used for development. This will usually originate from a source code repository, such as git. You can fetch this when you want to modify the downloaded package.

Packages can supply either of these, or even both. Depending on certain factors, such as user-supplied options and stability of the package, one will be preferred.

### Repository

A repository is a package source. It's a list of packages, of which you can pick some to install.

You can also add more repositories to your project by declaring them in `composer.json`.

## Types

### Composer

The main repository type is the `composer` repository. It uses a single `packages.json` file that contains all of the package metadata. The JSON format is as follows:

```json
{
    "vendor/packageName": {
        "name": "vendor/packageName",
        "description": "Package description",
        "versions": {
            "master-dev": { @composer.json },
            "1.0.0": { @composer.json }
        }
    }
}
```

The `@composer.json` marker would be the contents of the `composer.json` from that package version including as a minimum:

* name
* version
* dist or source

Here is a minimal package definition:

```json
{
    "name": "smarty/smarty",
    "version": "3.1.7",
    "dist": {
        "url": "http://www.smarty.net/files/Smarty-3.1.7.zip",
        "type": "zip"
    }
}
```

It may include any of the other fields specified in the [schema].

The `composer` repository is also what packagist uses. To reference a `composer` repository, just supply the path before the `packages.json` file. In case of packagist, that file is located at `/packages.json`, so the URL of the repository would be `http://packagist.org`. For `http://example.org/packages.org` the repository URL would be `http://example.org`.

### VCS

VCS stands for version control system. This includes versioning systems like git, svn or hg. Composer has a repository type for installing packages from these systems.

There are a few use cases for this. The most common one is maintaining your own fork of a third party library. If you are using a certain library for your project and you decide to change something in the library, you will want your project to use the patched version. If the library is on GitHub (this is the case most of the time), you can simply fork it there and push your changes to your fork. After that you update the project's `composer.json`. All you have to do is add your fork as a repository and update the version constraint to point to your custom branch.

Example assuming you patched monolog to fix a bug in the `bugfix` branch:

```json
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
```

When you run `php composer.phar update`, you should get your modified version of `monolog/monolog` instead of the one from packagist.

Git is not the only version control system supported by the VCS repository. The following are supported:

* **Git:** [git-scm.com](http://git-scm.com)
* **Subversion:** [subversion.apache.org](http://subversion.apache.org)
* **Mercurial:** [mercurial.selenic.com](http://mercurial.selenic.com)

To use these systems you need to have them installed. That can be invonvenient. And for this reason there is special support for GitHub and BitBucket that use the APIs provided by these sites, to fetch the packages without having to install the version control system. The VCS repository provides `dist`s for them that fetch the packages as zips.

* **GitHub:** [github.com](https://github.com) (Git)
* **BitBucket:** [bitbucket.org](https://bitbucket.org) (Git and Mercurial)

The VCS driver to be used is detected automatically based on the URL.

### PEAR

### Package

## Hosting your own
