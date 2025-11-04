<!--
    tagline: Hosting and installing private Composer packages
-->

# Handling private packages

# Private Packagist

[Private Packagist](https://packagist.com) is a commercial package hosting product
offering professional support and web based management of private and public packages,
and granular access permissions. Private Packagist provides mirroring for packages' zip
files which makes installs faster and independent from third party systems - e.g.
you can deploy even if GitHub is down because your zip files are mirrored.

Private Packagist is available as a hosted SaaS solution or as an on-premise self-hosted
package, providing an interactive set up experience.

Some of Private Packagist's revenue is used to pay for Composer and Packagist.org
development and hosting so using it is a good way to support the maintenance of
these open source projects financially. You can find more information about how to
set up your own package archive on [Packagist.com](https://packagist.com).

# Satis

Satis on the other hand is open source but only a static `composer` repository
generator. It is a bit like an ultra-lightweight, static file-based version of
packagist and can be used to host the metadata of your company's private
packages, or your own. You can install it using [Composer](https://github.com/composer/satis?tab=readme-ov-file#run-from-source)
or [Docker](https://github.com/composer/satis?tab=readme-ov-file#run-as-docker-container).

## Setup

For example let's assume you have a few packages you want to reuse across your
company but don't really want to open-source. You would first define a Satis
configuration: a json file that lists your curated
[repositories](../05-repositories.md).

The default file name is satis.json but it could be anything you like.

Here is an example configuration, you see that it holds a few VCS repositories,
but those could be any types of [repositories](../05-repositories.md). Then it
uses `"require-all": true` which selects all versions of all packages in the
repositories you defined.

The default file Satis looks for is `satis.json` in the root of the repository.

```json
{
    "name": "my/repository",
    "homepage": "http://packages.example.org",
    "repositories": [
        { "type": "vcs", "url": "https://github.com/mycompany/privaterepo" },
        { "type": "vcs", "url": "http://svn.example.org/private/repo" },
        { "type": "vcs", "url": "https://github.com/mycompany/privaterepo2" }
    ],
    "require-all": true
}
```

If you want to cherry pick which packages you want, you can list all the
packages you want to have in your satis repository inside the classic composer
`require` key, using a `"*"` constraint to make sure all versions are selected,
or another constraint if you want really specific versions.

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/mycompany/privaterepo" },
        { "type": "vcs", "url": "http://svn.example.org/private/repo" },
        { "type": "vcs", "url": "https://github.com/mycompany/privaterepo2" }
    ],
    "require": {
        "company/package": "*",
        "company/package2": "*",
        "company/package3": "2.0.0"
    }
}
```

Once you've done this, you run:

```bash
php bin/satis build <configuration file> <build dir>
```

When you ironed out that process, what you would typically do is run this
command as a cron job on a server. It would then update all your package info
much like Packagist does.

Note that if your private packages are hosted on GitHub, your server should
have an ssh key that gives it access to those packages, and then you should add
the `--no-interaction` (or `-n`) flag to the command to make sure it falls back
to ssh key authentication instead of prompting for a password. This is also a
good trick for continuous integration servers.

Set up a virtual-host that points to that `web/` directory, let's say it is
`packages.example.org`. Alternatively, with PHP >= 5.4.0, you can use the
built-in CLI server `php -S localhost:port -t satis-output-dir/` for a
temporary solution.

### Partial Updates

You can tell Satis to selectively update only particular packages or process
only a repository with a given URL. This cuts down the time it takes to rebuild
the `package.json` file and is helpful if you use (custom) webhooks to trigger
rebuilds whenever code is pushed into one of your repositories.

To rebuild only particular packages, pass the package names on the command line
like so:

```bash
php bin/satis build satis.json web/ this/package that/other-package
```

Note that this will still need to pull and scan all of your VCS repositories
because any VCS repository might contain (on any branch) one of the selected
packages.

If you want to scan only the selected package and not all VCS repositories you need
to declare a *name* for all your package (this only work on VCS repositories type) :

```json
{
    "repositories": [
        { "name": "company/privaterepo", "type": "vcs", "url": "https://github.com/mycompany/privaterepo" },
        { "name": "private/repo", "type": "vcs", "url": "http://svn.example.org/private/repo" },
        { "name": "mycompany/privaterepo2", "type": "vcs", "url": "https://github.com/mycompany/privaterepo2" }
    ]
}
```

If you want to scan only a single repository and update all packages found in
it, pass the VCS repository URL as an optional argument:

```bash
php bin/satis build --repository-url https://only.my/repo.git satis.json web/
```

## Usage

In your projects all you need to add now is your own Composer repository using
the `packages.example.org` as URL, then you can require your private packages
and everything should work smoothly. You don't need to copy all your
repositories in every project anymore. Only that one unique repository that
will update itself.

```json
{
    "repositories": [ { "type": "composer", "url": "http://packages.example.org/" } ],
    "require": {
        "company/package": "1.2.0",
        "company/package2": "1.5.2",
        "company/package3": "dev-master"
    }
}
```

### Security

To secure your private repository you can host it over SSH or SSL using a client
certificate. In your project you can use the `options` parameter to specify the
connection options for the server.

Example using a custom repository using SSH (requires the SSH2 PECL extension):

```json
{
    "repositories": [{
        "type": "composer",
        "url": "ssh2.sftp://example.org",
        "options": {
            "ssh2": {
                "username": "composer",
                "pubkey_file": "/home/composer/.ssh/id_rsa.pub",
                "privkey_file": "/home/composer/.ssh/id_rsa"
            }
        }
    }]
}
```

> **Tip:** See [ssh2 context options](https://secure.php.net/manual/en/wrappers.ssh2.php#refsect1-wrappers.ssh2-options) for more information.

Example using SSL/TLS (HTTPS) using a client certificate:

```json
{
    "repositories": [{
         "type": "composer",
         "url": "https://example.org",
         "options": {
             "ssl": {
                 "local_cert": "/home/composer/.ssl/composer.pem"
             }
         }
    }]
}
```

> **Tip:** See [ssl context options](https://secure.php.net/manual/en/context.ssl.php) for more information.

Example using a custom HTTP Header field for token authentication:

```json
{
    "repositories": [{
        "type": "composer",
        "url": "https://example.org",
        "options":  {
            "http": {
                "header": [
                    "API-TOKEN: YOUR-API-TOKEN"
                ]
            }
        }
    }]
}
```

### Authentication

Authentication can be handled in [several different ways](authentication-for-private-packages.md).

### Downloads

When GitHub, GitLab or BitBucket repositories are mirrored on your local satis, the
build process will include the location of the downloads these platforms make
available. This means that the repository and your setup depend on the
availability of these services.

At the same time, this implies that all code which is hosted somewhere else (on
another service or for example in Subversion) will not have downloads available
and thus installations usually take a lot longer.

To enable your satis installation to create downloads for all (Git, Mercurial
and Subversion) your packages, add the following to your `satis.json`:

```json
{
    "archive": {
        "directory": "dist",
        "format": "tar",
        "prefix-url": "https://amazing.cdn.example.org",
        "skip-dev": true
    }
}
```

#### Options explained

 * `directory`: required, the location of the dist files (inside the
   `output-dir`)
 * `format`: optional, `zip` (default) or `tar`
 * `prefix-url`: optional, location of the downloads, homepage (from
   `satis.json`) followed by `directory` by default
 * `skip-dev`: optional, `false` by default, when enabled (`true`) satis will
   not create downloads for branches
 * `absolute-directory`: optional, a _local_ directory where the dist files are
   dumped instead of `output-dir`/`directory`
 * `whitelist`: optional, if set as a list of package names, satis will only
   dump the dist files of these packages
 * `blacklist`: optional, if set as a list of package names, satis will not
   dump the dist files of these packages
 * `checksum`: optional, `true` by default, when disabled (`false`) satis will
   not provide the sha1 checksum for the dist files

Once enabled, all downloads (include those from GitHub and BitBucket) will be
replaced with a _local_ version.

#### prefix-url

Prefixing the URL with another host is especially helpful if the downloads end
up in a private Amazon S3 bucket or on a CDN host. A CDN would drastically
improve download times and therefore package installation.

Example: A `prefix-url` of `https://my-bucket.s3.amazonaws.com` (and
`directory` set to `dist`) creates download URLs which look like the following:
`https://my-bucket.s3.amazonaws.com/dist/vendor-package-version-ref.zip`.

### Web outputs

 * `output-html`: optional, `true` by default, when disabled (`false`) satis
   will not generate the `output-dir`/index.html page.
 * `twig-template`: optional, a path to a personalized [Twig](https://twig.sensiolabs.org/) template for
   the `output-dir`/index.html page.

### Abandoned packages

To enable your satis installation to indicate that some packages are abandoned,
add the following to your `satis.json`:

```json
{
    "abandoned": {
        "company/package": true,
        "company/package2": "company/newpackage"
    }
}
```

The `true` value indicates that the package is truly abandoned while the
`"company/newpackage"` value specifies that the package is replaced by the
`company/newpackage` package.

Note that all packages set as abandoned in their own `composer.json` file will
be marked abandoned as well.

### Resolving dependencies

It is possible to make satis automatically resolve and add all dependencies for
your projects. This can be used with the Downloads functionality to have a
complete local mirror of packages. Add the following to your `satis.json`:

```json
{
    "require-dependencies": true,
    "require-dev-dependencies": true
}
```

When searching for packages, satis will attempt to resolve all the required
packages from the listed repositories.  Therefore, if you are requiring a
package from Packagist, you will need to define it in your `satis.json`.

Dev dependencies are packaged only if the `require-dev-dependencies` parameter
is set to true.

### Other options

 * `providers`: optional, `false` by default, when enabled (`true`) each
   package will be dumped into a separate include file which will be only
   loaded by Composer when the package is really required. Speeds up composer
   handling for repositories with huge number of packages like f.i. packagist.
 * `output-dir`: optional, defines where to output the repository files if not
   provided as an argument when calling the `build` command.
 * `config`: optional, lets you define all config options from composer, except
   `archive-format` and `archive-dir` as the configuration is done through
   [archive](#downloads) instead. See docs on [config schema](../06-config.md) for more details.
 * `notify-batch`: optional, specify a URL that will be called every time a
   user installs a package. See [notify-batch](../05-repositories.md#notify-batch).
