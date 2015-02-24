<!--
    tagline: Host your own composer repository
-->

# Handling private packages with Satis or Toran Proxy

# Toran Proxy

[Toran Proxy](https://toranproxy.com/) is a commercial alternative to Satis
offering professional support as well as a web UI to manage everything and a
better integration with Composer. It also provides proxying/mirroring for git
repos and package zip files which makes installs faster and independent from
third party systems.

Toran's revenue is also used to pay for Composer and Packagist development and
hosting so using it is a good way to support open source financially. You can
find more information about how to set it up and use it on the [Toran Proxy](https://toranproxy.com/) website.

# Satis

Satis on the other hand is open source but only a static `composer`
repository generator. It is a bit like an ultra-lightweight, static file-based
version of packagist and can be used to host the metadata of your company's
private packages, or your own. You can get it from [GitHub](http://github.com/composer/satis)
or install via CLI:
`php composer.phar create-project composer/satis --stability=dev --keep-vcs`.

## Setup

For example let's assume you have a few packages you want to reuse across your
company but don't really want to open-source. You would first define a Satis
configuration: a json file with an arbitrary name that lists your curated 
[repositories](../05-repositories.md).

Here is an example configuration, you see that it holds a few VCS repositories,
but those could be any types of [repositories](../05-repositories.md). Then it
uses `"require-all": true` which selects all versions of all packages in the
repositories you defined.

The default file Satis looks for is `satis.json` in the root of the repository.

```json
{
    "name": "My Repository",
    "homepage": "http://packages.example.org",
    "repositories": [
        { "type": "vcs", "url": "http://github.com/mycompany/privaterepo" },
        { "type": "vcs", "url": "http://svn.example.org/private/repo" },
        { "type": "vcs", "url": "http://github.com/mycompany/privaterepo2" }
    ],
    "require-all": true
}
```

If you want to cherry pick which packages you want, you can list all the packages
you want to have in your satis repository inside the classic composer `require` key,
using a `"*"` constraint to make sure all versions are selected, or another
constraint if you want really specific versions.

```json
{
    "repositories": [
        { "type": "vcs", "url": "http://github.com/mycompany/privaterepo" },
        { "type": "vcs", "url": "http://svn.example.org/private/repo" },
        { "type": "vcs", "url": "http://github.com/mycompany/privaterepo2" }
    ],
    "require": {
        "company/package": "*",
        "company/package2": "*",
        "company/package3": "2.0.0"
    }
}
```

Once you've done this, you just run `php bin/satis build <configuration file> <build dir>`.
For example `php bin/satis build config.json web/` would read the `config.json`
file and build a static repository inside the `web/` directory.

When you ironed out that process, what you would typically do is run this
command as a cron job on a server. It would then update all your package info
much like Packagist does.

Note that if your private packages are hosted on GitHub, your server should have
an ssh key that gives it access to those packages, and then you should add
the `--no-interaction` (or `-n`) flag to the command to make sure it falls back
to ssh key authentication instead of prompting for a password. This is also a
good trick for continuous integration servers.

Set up a virtual-host that points to that `web/` directory, let's say it is
`packages.example.org`. Alternatively, with PHP >= 5.4.0, you can use the built-in
CLI server `php -S localhost:port -t satis-output-dir/` for a temporary solution.

## Usage

In your projects all you need to add now is your own composer repository using
the `packages.example.org` as URL, then you can require your private packages and
everything should work smoothly. You don't need to copy all your repositories
in every project anymore. Only that one unique repository that will update
itself.

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
    "repositories": [
        {
            "type": "composer",
            "url": "ssh2.sftp://example.org",
            "options": {
                "ssh2": {
                    "username": "composer",
                    "pubkey_file": "/home/composer/.ssh/id_rsa.pub",
                    "privkey_file": "/home/composer/.ssh/id_rsa"
                }
            }
        }
    ]
}
```

> **Tip:** See [ssh2 context options](http://www.php.net/manual/en/wrappers.ssh2.php#refsect1-wrappers.ssh2-options) for more information.

Example using HTTP over SSL using a client certificate:

```json
{
    "repositories": [
        {
            "type": "composer",
            "url": "https://example.org",
            "options": {
                "ssl": {
                    "local_cert": "/home/composer/.ssl/composer.pem"
                }
            }
        }
    ]
}
```

> **Tip:** See [ssl context options](http://www.php.net/manual/en/context.ssl.php) for more information.

### Downloads

When GitHub or BitBucket repositories are mirrored on your local satis, the build process will include
the location of the downloads these platforms make available. This means that the repository and your setup depend
on the availability of these services.

At the same time, this implies that all code which is hosted somewhere else (on another service or for example in
Subversion) will not have downloads available and thus installations usually take a lot longer.

To enable your satis installation to create downloads for all (Git, Mercurial and Subversion) your packages, add the
following to your `satis.json`:

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

 * `directory`: the location of the dist files (inside the `output-dir`)
 * `format`: optional, `zip` (default) or `tar`
 * `prefix-url`: optional, location of the downloads, homepage (from `satis.json`) followed by `directory` by default
 * `skip-dev`: optional, `false` by default, when enabled (`true`) satis will not create downloads for branches

Once enabled, all downloads (include those from GitHub and BitBucket) will be replaced with a _local_ version.

#### prefix-url

Prefixing the URL with another host is especially helpful if the downloads end up in a private Amazon S3
bucket or on a CDN host. A CDN would drastically improve download times and therefore package installation.

Example: A `prefix-url` of `http://my-bucket.s3.amazonaws.com` (and `directory` set to `dist`) creates download URLs
which look like the following: `http://my-bucket.s3.amazonaws.com/dist/vendor-package-version-ref.zip`.


### Resolving dependencies

It is possible to make satis automatically resolve and add all dependencies for your projects. This can be used
with the Downloads functionality to have a complete local mirror of packages. Just add the following
to your `satis.json`:

```json
{
    "require-dependencies": true,
    "require-dev-dependencies": true
}
```

When searching for packages, satis will attempt to resolve all the required packages from the listed repositories.
Therefore, if you are requiring a package from Packagist, you will need to define it in your `satis.json`.

Dev dependencies are packaged only if the `require-dev-dependencies` parameter is set to true.
