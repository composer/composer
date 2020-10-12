# Config

This chapter will describe the `config` section of the `composer.json`
[schema](04-schema.md).

## process-timeout

Defaults to `300`. The duration processes like git clones can run before
Composer assumes they died out. You may need to make this higher if you have a
slow connection or huge vendors.

To disable the process timeout on a custom command under `scripts`, a static
helper is available:

```json
{
    "scripts": {
        "test": [
            "Composer\\Config::disableProcessTimeout",
            "phpunit"
        ]
    }
}
```

## use-include-path

Defaults to `false`. If `true`, the Composer autoloader will also look for classes
in the PHP include path.

## preferred-install

Defaults to `auto` and can be any of `source`, `dist` or `auto`. This option
allows you to set the install method Composer will prefer to use. Can
optionally be a hash of patterns for more granular install preferences.

```json
{
    "config": {
        "preferred-install": {
            "my-organization/stable-package": "dist",
            "my-organization/*": "source",
            "partner-organization/*": "auto",
            "*": "dist"
        }
    }
}
```

> **Note:** Order matters. More specific patterns should be earlier than
> more relaxed patterns. When mixing the string notation with the hash
> configuration in global and package configurations the string notation
> is translated to a `*` package pattern.

## store-auths

What to do after prompting for authentication, one of: `true` (always store),
`false` (do not store) and `"prompt"` (ask every time), defaults to `"prompt"`.

## github-protocols

Defaults to `["https", "ssh", "git"]`. A list of protocols to use when cloning
from github.com, in priority order. By default `git` is present but only if [secure-http](#secure-http)
is disabled, as the git protocol is not encrypted. If you want your origin remote
push URLs to be using https and not ssh (`git@github.com:...`), then set the protocol
list to be only `["https"]` and Composer will stop overwriting the push URL to an ssh
URL.

## github-oauth

A list of domain names and oauth keys. For example using `{"github.com":
"oauthtoken"}` as the value of this option will use `oauthtoken` to access
private repositories on github and to circumvent the low IP-based rate limiting
of their API. [Read
more](articles/troubleshooting.md#api-rate-limit-and-oauth-tokens) on how to get
an OAuth token for GitHub.

## gitlab-oauth

A list of domain names and oauth keys. For example using `{"gitlab.com":
"oauthtoken"}` as the value of this option will use `oauthtoken` to access
private repositories on gitlab. Please note: If the package is not hosted at
gitlab.com the domain names must be also specified with the
[`gitlab-domains`](06-config.md#gitlab-domains) option.

## gitlab-token

A list of domain names and private tokens. For example using `{"gitlab.com":
"privatetoken"}` as the value of this option will use `privatetoken` to access
private repositories on gitlab. Please note: If the package is not hosted at
gitlab.com the domain names must be also specified with the
[`gitlab-domains`](06-config.md#gitlab-domains) option.

## disable-tls

Defaults to `false`. If set to true all HTTPS URLs will be tried with HTTP
instead and no network level encryption is performed. Enabling this is a
security risk and is NOT recommended. The better way is to enable the
php_openssl extension in php.ini. Enabling this will implicitly disable the
`secure-http` option.

## secure-http

Defaults to `true`. If set to true only HTTPS URLs are allowed to be
downloaded via Composer. If you really absolutely need HTTP access to something
then you can disable it, but using [Let's Encrypt](https://letsencrypt.org/) to
get a free SSL certificate is generally a better alternative.

## bitbucket-oauth

A list of domain names and consumers. For example using `{"bitbucket.org":
{"consumer-key": "myKey", "consumer-secret": "mySecret"}}`. [Read](https://confluence.atlassian.com/bitbucket/oauth-on-bitbucket-cloud-238027431.html)
how to set up a consumer on Bitbucket.

## cafile

Location of Certificate Authority file on local filesystem. In PHP 5.6+ you
should rather set this via openssl.cafile in php.ini, although PHP 5.6+ should
be able to detect your system CA file automatically.

## capath

If cafile is not specified or if the certificate is not found there, the
directory pointed to by capath is searched for a suitable certificate.
capath must be a correctly hashed certificate directory.

## http-basic

A list of domain names and username/passwords to authenticate against them. For
example using `{"example.org": {"username": "alice", "password": "foo"}}` as the
value of this option will let Composer authenticate against example.org.

> **Note:** Authentication-related config options like `http-basic`, `bearer` and
> `github-oauth` can also be specified inside a `auth.json` file that goes
> besides your `composer.json`. That way you can gitignore it and every
> developer can place their own credentials in there.

## bearer

A list of domain names and tokens to authenticate against them. For example using
`{"example.org": "foo"}` as the value of this option will let Composer authenticate
against example.org using an `Authorization: Bearer foo` header.

## platform

Lets you fake platform packages (PHP and extensions) so that you can emulate a
production env or define your target platform in the config. Example: `{"php":
"7.0.3", "ext-something": "4.0.3"}`.

## vendor-dir

Defaults to `vendor`. You can install dependencies into a different directory if
you want to. `$HOME` and `~` will be replaced by your home directory's path in
vendor-dir and all `*-dir` options below.

## bin-dir

Defaults to `vendor/bin`. If a project includes binaries, they will be symlinked
into this directory.

## data-dir

Defaults to `C:\Users\<user>\AppData\Roaming\Composer` on Windows,
`$XDG_DATA_HOME/composer` on unix systems that follow the XDG Base Directory
Specifications, and `$home` on other unix systems. Right now it is only
used for storing past composer.phar files to be able to rollback to older
versions. See also [COMPOSER_HOME](03-cli.md#composer-home).

## cache-dir

Defaults to `C:\Users\<user>\AppData\Local\Composer` on Windows,
`$XDG_CACHE_HOME/composer` on unix systems that follow the XDG Base Directory
Specifications, and `$home/cache` on other unix systems. Stores all the caches
used by Composer. See also [COMPOSER_HOME](03-cli.md#composer-home).

## cache-files-dir

Defaults to `$cache-dir/files`. Stores the zip archives of packages.

## cache-repo-dir

Defaults to `$cache-dir/repo`. Stores repository metadata for the `composer`
type and the VCS repos of type `svn`, `fossil`, `github` and `bitbucket`.

## cache-vcs-dir

Defaults to `$cache-dir/vcs`. Stores VCS clones for loading VCS repository
metadata for the `git`/`hg` types and to speed up installs.

## cache-files-ttl

Defaults to `15552000` (6 months). Composer caches all dist (zip, tar, ..)
packages that it downloads. Those are purged after six months of being unused by
default. This option allows you to tweak this duration (in seconds) or disable
it completely by setting it to 0.

## cache-files-maxsize

Defaults to `300MiB`. Composer caches all dist (zip, tar, ..) packages that it
downloads. When the garbage collection is periodically ran, this is the maximum
size the cache will be able to use. Older (less used) files will be removed
first until the cache fits.

## bin-compat

Defaults to `auto`. Determines the compatibility of the binaries to be installed.
If it is `auto` then Composer only installs .bat proxy files when on Windows. If
set to `full` then both .bat files for Windows and scripts for Unix-based
operating systems will be installed for each binary. This is mainly useful if you
run Composer inside a linux VM but still want the .bat proxies available for use
in the Windows host OS.

## prepend-autoloader

Defaults to `true`. If `false`, the Composer autoloader will not be prepended to
existing autoloaders. This is sometimes required to fix interoperability issues
with other autoloaders.

## autoloader-suffix

Defaults to `null`. String to be used as a suffix for the generated Composer
autoloader. When null a random one will be generated.

## optimize-autoloader

Defaults to `false`. If `true`, always optimize when dumping the autoloader.

## sort-packages

Defaults to `false`. If `true`, the `require` command keeps packages sorted
by name in `composer.json` when adding a new package.

## classmap-authoritative

Defaults to `false`. If `true`, the Composer autoloader will only load classes
from the classmap. Implies `optimize-autoloader`.

## apcu-autoloader

Defaults to `false`. If `true`, the Composer autoloader will check for APCu and
use it to cache found/not-found classes when the extension is enabled.

## github-domains

Defaults to `["github.com"]`. A list of domains to use in github mode. This is
used for GitHub Enterprise setups.

## github-expose-hostname

Defaults to `true`. If `false`, the OAuth tokens created to access the
github API will have a date instead of the machine hostname.

## gitlab-domains

Defaults to `["gitlab.com"]`. A list of domains of GitLab servers.
This is used if you use the `gitlab` repository type.

## use-github-api

Defaults to `true`.  Similar to the `no-api` key on a specific repository,
setting `use-github-api` to `false` will define the global behavior for all
GitHub repositories to clone the repository as it would with any other git
repository instead of using the GitHub API. But unlike using the `git`
driver directly, Composer will still attempt to use GitHub's zip files.

## notify-on-install

Defaults to `true`. Composer allows repositories to define a notification URL,
so that they get notified whenever a package from that repository is installed.
This option allows you to disable that behavior.

## discard-changes

Defaults to `false` and can be any of `true`, `false` or `"stash"`. This option
allows you to set the default style of handling dirty updates when in
non-interactive mode. `true` will always discard changes in vendors, while
`"stash"` will try to stash and reapply. Use this for CI servers or deploy
scripts if you tend to have modified vendors.

## archive-format

Defaults to `tar`. Composer allows you to add a default archive format when the
workflow needs to create a dedicated archiving format.

## archive-dir

Defaults to `.`. Composer allows you to add a default archive directory when the
workflow needs to create a dedicated archiving format. Or for easier development
between modules.

Example:

```json
{
    "config": {
        "archive-dir": "/home/user/.composer/repo"
    }
}
```

## htaccess-protect

Defaults to `true`. If set to `false`, Composer will not create `.htaccess` files
in the composer home, cache, and data directories.

## lock

Defaults to `true`. If set to `false`, Composer will not create a `composer.lock`
file.

&larr; [Repositories](05-repositories.md)  |  [Community](07-community.md) &rarr;
