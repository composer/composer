# Repositories

This chapter will explain the concept of packages and repositories, what kinds
of repositories are available, and how they work.

## Concepts

Before we look at the different types of repositories that exist, we need to
understand some basic concepts that Composer is built on.

### Package

Composer is a dependency manager. It installs packages locally. A package is
essentially a directory containing something. In this case it is PHP
code, but in theory it could be anything. And it contains a package
description which has a name and a version. The name and the version are used
to identify the package.

In fact, internally, Composer sees every version as a separate package. While
this distinction does not matter when you are using Composer, it's quite
important when you want to change it.

In addition to the name and the version, there is useful metadata. The
information most relevant for installation is the source definition, which
describes where to get the package contents. The package data points to the
contents of the package. And there are two options here: dist and source.

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

By default, only the Packagist.org repository is registered in Composer. You can
add more repositories to your project by declaring them in `composer.json`.

Repositories are only available to the root package and the repositories
defined in your dependencies will not be loaded. Read the
[FAQ entry](faqs/why-cant-composer-load-repositories-recursively.md) if you
want to learn why.

When resolving dependencies, packages are looked up from repositories from
top to bottom, and by default, as soon as a package is found in one, Composer
stops looking in other repositories. Read the
[repository priorities](articles/repository-priorities.md) article for more
details and to see how to change this behavior.

## Types

### Composer

The main repository type is the `composer` repository. It uses a single
`packages.json` file that contains all of the package metadata.

This is also the repository type that packagist uses. To reference a
`composer` repository, supply the path before the `packages.json` file.
In the case of packagist, that file is located at `/packages.json`, so the URL of
the repository would be `repo.packagist.org`. For `example.org/packages.json` the
repository URL would be `example.org`.

```json
{
    "repositories": [
        {
            "type": "composer",
            "url": "https://example.org"
        }
    ]
}
```

#### packages

The only required field is `packages`. The JSON structure is as follows:

```json
{
    "packages": {
        "vendor/package-name": {
            "dev-master": { @composer.json },
            "1.0.x-dev": { @composer.json },
            "0.0.1": { @composer.json },
            "1.0.0": { @composer.json }
        }
    }
}
```

The `@composer.json` marker would be the contents of the `composer.json` from
that package version including as a minimum:

* name
* version
* dist or source

Here is a minimal package definition:

```json
{
    "name": "smarty/smarty",
    "version": "3.1.7",
    "dist": {
        "url": "https://www.smarty.net/files/Smarty-3.1.7.zip",
        "type": "zip"
    }
}
```

It may include any of the other fields specified in the [schema](04-schema.md).

#### notify-batch

The `notify-batch` field allows you to specify a URL that will be called
every time a user installs a package. The URL can be either an absolute path
(that will use the same domain as the repository), or a fully qualified URL.

An example value:

```json
{
    "notify-batch": "/downloads/"
}
```

For `example.org/packages.json` containing a `monolog/monolog` package, this
would send a `POST` request to `example.org/downloads/` with following
JSON request body:

```json
{
    "downloads": [
        {"name": "monolog/monolog", "version": "1.2.1.0"}
    ]
}
```

The version field will contain the normalized representation of the version
number.

This field is optional.

#### metadata-url, available-packages and available-package-patterns

The `metadata-url` field allows you to provide a URL template to serve all
packages which are in the repository. It must contain the placeholder
`%package%`.

This field is new in Composer v2, and is prioritised over the
`provider-includes` and `providers-url` fields if both are present.
For compatibility with both Composer v1 and v2 you ideally want
to provide both. New repository implementations may only need to
support v2 however.

An example:

```json
{
    "metadata-url": "/p2/%package%.json"
}
```

Whenever Composer looks for a package, it will replace `%package%` by the
package name, and fetch that URL. If dev stability is allowed for the package,
it will also load the URL again with `$packageName~dev` (e.g.
`/p2/foo/bar~dev.json` to look for `foo/bar`'s dev versions).

The `foo/bar.json` and `foo/bar~dev.json` files containing package versions
MUST contain only versions for the foo/bar package, as
`{"packages":{"foo/bar":[ ... versions here ... ]}}`.

Caching is done via the use of If-Modified-Since header, so make sure you
return Last-Modified headers and that they are accurate.

The array of versions can also optionally be minified using
`Composer\MetadataMinifier\MetadataMinifier::minify()` from
[composer/metadata-minifier](https://packagist.org/packages/composer/metadata-minifier).
If you do that, you should add a `"minified": "composer/2.0"` key
at the top level to indicate to Composer it must expand the version
list back into the original data. See
https://repo.packagist.org/p2/monolog/monolog.json for an example.

Any requested package which does not exist MUST return a 404 status code,
which will indicate to Composer that this package does not exist in your
repository. Make sure the 404 response is fast to avoid blocking Composer.
Avoid redirects to alternative 404 pages.

If your repository only has a small number of packages, and you want to avoid
the 404-requests, you can also specify an `"available-packages"` key in
`packages.json` which should be an array with all the package names that your
repository contains. Alternatively you can specify an
`"available-package-patterns"` key which is an array of package name patterns
(with `*` matching any string, e.g. `vendor/*` would make Composer look up
every matching package name in this repository).

This field is optional.

#### providers-api

The `providers-api` field allows you to provide a URL template to serve all
packages which provide a given package name, but not the package which has
that name. It must contain the placeholder `%package%`.

For example https://packagist.org/providers/monolog/monolog.json lists some
package which have a "provide" rule for monolog/monolog, but it does not list
monolog/monolog itself.

```json
{
    "providers-api": "https://packagist.org/providers/%package%.json",
}
```

This field is optional.

#### list

The `list` field allows you to return the names of packages which match a
given field (or all names if no filter is present). It should accept an
optional `?filter=xx` query param, which can contain `*` as wildcards matching
any substring.

Replace/provide rules should not be considered here.

It must return an array of package names:
```json
{
    "packageNames": [
        "a/b",
        "c/d"
    ]
}
```

See <https://packagist.org/packages/list.json?filter=composer/*> for example.

This field is optional.

#### provider-includes and providers-url

The `provider-includes` field allows you to list a set of files that list
package names provided by this repository. The hash should be a sha256 of
the files in this case.

The `providers-url` describes how provider files are found on the server. It
is an absolute path from the repository root. It must contain the placeholders
`%package%` and `%hash%`.

These fields are used by Composer v1, or if your repository does not have the
`metadata-url` field set.

An example:

```json
{
    "provider-includes": {
        "providers-a.json": {
            "sha256": "f5b4bc0b354108ef08614e569c1ed01a2782e67641744864a74e788982886f4c"
        },
        "providers-b.json": {
            "sha256": "b38372163fac0573053536f5b8ef11b86f804ea8b016d239e706191203f6efac"
        }
    },
    "providers-url": "/p/%package%$%hash%.json"
}
```

Those files contain lists of package names and hashes to verify the file
integrity, for example:

```json
{
    "providers": {
        "acme/foo": {
            "sha256": "38968de1305c2e17f4de33aea164515bc787c42c7e2d6e25948539a14268bb82"
        },
        "acme/bar": {
            "sha256": "4dd24c930bd6e1103251306d6336ac813b563a220d9ca14f4743c032fb047233"
        }
    }
}
```

The file above declares that acme/foo and acme/bar can be found in this
repository, by loading the file referenced by `providers-url`, replacing
`%package%` by the vendor namespaced package name and `%hash%` by the
sha256 field. Those files themselves contain package definitions as
described [above](#packages).

These fields are optional. You probably don't need them for your own custom
repository.

#### cURL or stream options

The repository is accessed either using cURL (Composer 2 with ext-curl enabled)
or PHP streams. You can set extra options using the `options` parameter. For
PHP streams, you can set any valid PHP stream context option. See [Context
options and parameters](https://php.net/manual/en/context.php) for more
information. When cURL is used, only a limited set of `http` and `ssl` options
can be configured.

```json
{
    "repositories": [
        {
            "type": "composer",
            "url": "https://example.org",
            "options": {
                "http": {
                    "timeout": 60
                }
            }
        }
    ],
    "require": {
        "acme/package": "^1.0"
    }
}
```

### VCS

VCS stands for version control system. This includes versioning systems like
git, svn, fossil or hg. Composer has a repository type for installing packages
from these systems.

#### Loading a package from a VCS repository

There are a few use cases for this. The most common one is maintaining your
own fork of a third party library. If you are using a certain library for your
project, and you decide to change something in the library, you will want your
project to use the patched version. If the library is on GitHub (this is the
case most of the time), you can fork it there and push your changes to
your fork. After that you update the project's `composer.json`. All you have
to do is add your fork as a repository and update the version constraint to
point to your custom branch. In `composer.json` only, you should prefix your
custom branch name with `"dev-"` (without making it part of the actual branch
name). For version constraint naming conventions see
[Libraries](02-libraries.md) for more information.

Example assuming you patched monolog to fix a bug in the `bugfix` branch:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/igorw/monolog"
        }
    ],
    "require": {
        "monolog/monolog": "dev-bugfix"
    }
}
```

When you run `php composer.phar update`, you should get your modified version
of `monolog/monolog` instead of the one from packagist.

Note that you should not rename the package unless you really intend to fork
it in the long term, and completely move away from the original package.
Composer will correctly pick your package over the original one since the
custom repository has priority over packagist. If you want to rename the
package, you should do so in the default (often master) branch and not in a
feature branch, since the package name is taken from the default branch.

Also note that the override will not work if you change the `name` property
in your forked repository's `composer.json` file as this needs to match the
original for the override to work.

If other dependencies rely on the package you forked, it is possible to
inline-alias it so that it matches a constraint that it otherwise would not.
For more information [see the aliases article](articles/aliases.md).

#### Using private repositories

Exactly the same solution allows you to work with your private repositories at
GitHub and Bitbucket:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url":  "git@bitbucket.org:vendor/my-private-repo.git"
        }
    ],
    "require": {
        "vendor/my-private-repo": "dev-master"
    }
}
```

The only requirement is the installation of SSH keys for a git client.

#### Git alternatives

Git is not the only version control system supported by the VCS repository.
The following are supported:

* **Git:** [git-scm.com](https://git-scm.com)
* **Subversion:** [subversion.apache.org](https://subversion.apache.org)
* **Mercurial:** [mercurial-scm.org](https://www.mercurial-scm.org)
* **Fossil**: [fossil-scm.org](https://www.fossil-scm.org/)

To get packages from these systems you need to have their respective clients
installed. That can be inconvenient. And for this reason there is special
support for GitHub and Bitbucket that use the APIs provided by these sites, to
fetch the packages without having to install the version control system. The
VCS repository provides `dist`s for them that fetch the packages as zips.

* **GitHub:** [github.com](https://github.com) (Git)
* **Bitbucket:** [bitbucket.org](https://bitbucket.org) (Git)

The VCS driver to be used is detected automatically based on the URL. However,
should you need to specify one for whatever reason, you can use `bitbucket`,
`github`, `gitlab`, `perforce`, `fossil`, `git`, `svn` or `hg`
as the repository type instead of `vcs`.

If you set the `no-api` key to `true` on a github repository it will clone the
repository as it would with any other git repository instead of using the
GitHub API. But unlike using the `git` driver directly, Composer will still
attempt to use github's zip files.

Please note:
* **To let Composer choose which driver to use** the repository type needs to be defined as "vcs"
* **If you already used a private repository**, this means Composer should have cloned it in cache. If you want to install the same package with drivers, remember to launch the command `composer clearcache` followed by the command `composer update` to update Composer cache and install the package from dist.
* VCS driver `git-bitbucket` is deprecated in favor of `bitbucket`

#### Bitbucket Driver Configuration

> **Note that the repository endpoint for Bitbucket needs to be https rather than git.**

After setting up your bitbucket repository, you will also need to
[set up authentication](articles/authentication-for-private-packages.md#bitbucket-oauth).

#### Subversion Options

Since Subversion has no native concept of branches and tags, Composer assumes
by default that code is located in `$url/trunk`, `$url/branches` and
`$url/tags`. If your repository has a different layout you can change those
values. For example if you used capitalized names you could configure the
repository like this:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "http://svn.example.org/projectA/",
            "trunk-path": "Trunk",
            "branches-path": "Branches",
            "tags-path": "Tags"
        }
    ]
}
```

If you have no branches or tags directory you can disable them entirely by
setting the `branches-path` or `tags-path` to `false`.

If the package is in a subdirectory, e.g. `/trunk/foo/bar/composer.json` and
`/tags/1.0/foo/bar/composer.json`, then you can make Composer access it by
setting the `"package-path"` option to the sub-directory, in this example it
would be `"package-path": "foo/bar/"`.

If you have a private Subversion repository you can save credentials in the
http-basic section of your config (See [Schema](04-schema.md)):

```json
{
    "http-basic": {
        "svn.example.org": {
            "username": "username",
            "password": "password"
        }
    }
}
```

If your Subversion client is configured to store credentials by default these
credentials will be saved for the current user and existing saved credentials
for this server will be overwritten. To change this behavior by setting the
`"svn-cache-credentials"` option in your repository configuration:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "http://svn.example.org/projectA/",
            "svn-cache-credentials": false
        }
    ]
}
```

### Package

If you want to use a project that does not support Composer through any of the
means above, you still can define the package yourself by using a `package`
repository.

Basically, you define the same information that is included in the `composer`
repository's `packages.json`, but only for a single package. Again, the
minimum required fields are `name`, `version`, and either of `dist` or
`source`.

Here is an example for the smarty template engine:

```json
{
    "repositories": [
        {
            "type": "package",
            "package": {
                "name": "smarty/smarty",
                "version": "3.1.7",
                "dist": {
                    "url": "https://www.smarty.net/files/Smarty-3.1.7.zip",
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
```

Typically, you would leave the source part off, as you don't really need it.

> **Note**: This repository type has a few limitations and should be avoided
> whenever possible:
>
> - Composer will not update the package unless you change the `version` field.
> - Composer will not update the commit references, so if you use `master` as
>   reference you will have to delete the package to force an update, and will
>   have to deal with an unstable lock file.

The `"package"` key in a `package` repository may be set to an array to define multiple versions of a package:

```json
{
    "repositories": [
        {
            "type": "package",
            "package": [
                {
                    "name": "foo/bar",
                    "version": "1.0.0",
                    ...
                },
                {
                    "name": "foo/bar",
                    "version": "2.0.0",
                    ...
                }
            ]
        }
    ]
}
```

## Hosting your own

While you will probably want to put your packages on packagist most of the
time, there are some use cases for hosting your own repository.

* **Private company packages:** If you are part of a company that uses Composer
  for their packages internally, you might want to keep those packages private.

* **Separate ecosystem:** If you have a project which has its own ecosystem,
  and the packages aren't really reusable by the greater PHP community, you
  might want to keep them separate to packagist. An example of this would be
  WordPress plugins.

For hosting your own packages, a native `composer` type of repository is
recommended, which provides the best performance.

There are a few tools that can help you create a `composer` repository.

### Private Packagist

[Private Packagist](https://packagist.com/) is a hosted or self-hosted
application providing private package hosting as well as mirroring of
GitHub, Packagist.org and other package repositories.

Check out [Packagist.com](https://packagist.com/) for more information.

### Satis

Satis is a static `composer` repository generator. It is a bit like an ultra-
lightweight, static file-based version of packagist.

You give it a `composer.json` containing repositories, typically VCS and
package repository definitions. It will fetch all the packages that are
`require`d and dump a `packages.json` that is your `composer` repository.

Check [the satis GitHub repository](https://github.com/composer/satis) and
the [handling private packages article](articles/handling-private-packages.md) for more
information.

### Artifact

There are some cases, when there is no ability to have one of the previously
mentioned repository types online, even the VCS one. A typical example could be
cross-organisation library exchange through build artifacts. Of course, most
of the time these are private. To use these archives as-is, one can use a
repository of type `artifact` with a folder containing ZIP or TAR archives of
those private packages:

```json
{
    "repositories": [
        {
            "type": "artifact",
            "url": "path/to/directory/with/zips/"
        }
    ],
    "require": {
        "private-vendor-one/core": "15.6.2",
        "private-vendor-two/connectivity": "*",
        "acme-corp/parser": "10.3.5"
    }
}
```

Each zip artifact is a ZIP archive with `composer.json` in root folder:

```shell
unzip -l acme-corp-parser-10.3.5.zip
```
```text
composer.json
...
```

If there are two archives with different versions of a package, they are both
imported. When an archive with a newer version is added in the artifact folder
and you run `update`, that version will be imported as well and Composer will
update to the latest version.

### Path

In addition to the artifact repository, you can use the path one, which allows
you to depend on a local directory, either absolute or relative. This can be
especially useful when dealing with monolithic repositories.

For instance, if you have the following directory structure in your repository:
```text
...
├── apps
│   └── my-app
│       └── composer.json
├── packages
│   └── my-package
│       └── composer.json
...
```

Then, to add the package `my/package` as a dependency, in your
`apps/my-app/composer.json` file, you can use the following configuration:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "../../packages/my-package"
        }
    ],
    "require": {
        "my/package": "*"
    }
}
```

If the package is a local VCS repository, the version may be inferred by
the branch or tag that is currently checked out. Otherwise, the version should
be explicitly defined in the package's `composer.json` file. If the version
cannot be resolved by these means, it is assumed to be `dev-master`.

When the version cannot be inferred from the local VCS repository, or when you
want to override the version, you can use the `versions` option when declaring
the repository:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "../../packages/my-package",
            "options": {
                "versions": {
                    "my/package": "4.2-dev"
                }
            }
        }
    ]
}
```

The local package will be symlinked if possible, in which case the output in
the console will read `Symlinking from ../../packages/my-package`. If symlinking
is _not_ possible the package will be copied. In that case, the console will
output `Mirrored from ../../packages/my-package`.

Instead of default fallback strategy you can force to use symlink with
`"symlink": true` or mirroring with `"symlink": false` option. Forcing
mirroring can be useful when deploying or generating package from a
monolithic repository.

> **Note:** On Windows, directory symlinks are implemented using NTFS junctions
> because they can be created by non-admin users. Mirroring will always be used
> on versions below Windows 7 or if `proc_open` has been disabled.

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "../../packages/my-package",
            "options": {
                "symlink": false
            }
        }
    ]
}
```

Leading tildes are expanded to the current user's home folder, and environment
variables are parsed in both Windows and Linux/Mac notations. For example
`~/git/mypackage` will automatically load the mypackage clone from
`/home/<username>/git/mypackage`, equivalent to `$HOME/git/mypackage` or
`%USERPROFILE%/git/mypackage`.

> **Note:** Repository paths can also contain wildcards like `*` and `?`.
> For details, see the [PHP glob function](https://php.net/glob).

You can configure the way the package's dist reference (which appears in
the composer.lock file) is built.

The following modes exist:
- `none` - reference will be always null. This can help reduce lock file conflicts
  in the lock file but reduces clarity as to when the last update happened and whether
  the package is in the latest state.
- `config` - reference is built based on a hash of the package's composer.json and repo config
- `auto` (used by default) - reference is built basing on the hash like with `config`, but if
  the package folder contains a git repository, the HEAD commit's hash is used as reference instead.

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "../../packages/my-package",
            "options": {
                "reference": "config"
            }
        }
    ]
}
```

## Disabling Packagist.org

You can disable the default Packagist.org repository by adding this to your
`composer.json`:

```json
{
    "repositories": [
        {
            "packagist.org": false
        }
    ]
}
```

You can disable Packagist.org globally by using the global config flag:

```shell
php composer.phar config -g repo.packagist false
```

&larr; [Schema](04-schema.md)  |  [Config](06-config.md) &rarr;
