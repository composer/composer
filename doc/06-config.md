# Config

This chapter will describe the `config` section of the `composer.json`
[schema](04-schema.md).

## process-timeout

Defaults to `300`. The duration processes like git clones can run before
Composer assumes they died out. You may need to make this higher if you have a
slow connection or huge vendors.

## use-include-path

Defaults to `false`. If `true`, the Composer autoloader will also look for classes
in the PHP include path.

## preferred-install

Defaults to `auto` and can be any of `source`, `dist` or `auto`. This option
allows you to set the install method Composer will prefer to use.

## store-auths

What to do after prompting for authentication, one of: `true` (always store),
`false` (do not store) and `"prompt"` (ask every time), defaults to `"prompt"`.

## github-protocols

Defaults to `["git", "https", "ssh"]`. A list of protocols to use when cloning
from github.com, in priority order. You can reconfigure it to for example
prioritize the https protocol if you are behind a proxy or have somehow bad
performances with the git protocol.

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
private repositories on gitlab.

## disable-tls

Defaults to `false`. If set to true all HTTPS URLs will be tried with HTTP
instead and no network level encryption is performed. Enabling this is a
security risk and is NOT recommended. The better way is to enable the
php_openssl extension in php.ini.

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
example using `{"example.org": {"username": "alice", "password": "foo"}` as the
value of this option will let Composer authenticate against example.org.

> **Note:** Authentication-related config options like `http-basic` and
> `github-oauth` can also be specified inside a `auth.json` file that goes
> besides your `composer.json`. That way you can gitignore it and every
> developer can place their own credentials in there.

## platform

Lets you fake platform packages (PHP and extensions) so that you can emulate a
production env or define your target platform in the config. Example: `{"php":
"5.4", "ext-something": "4.0"}`.

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
type and the VCS repos of type `svn`, `github` and `bitbucket`.

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

## github-domains

Defaults to `["github.com"]`. A list of domains to use in github mode. This is
used for GitHub Enterprise setups.

## github-expose-hostname

Defaults to `true`. If `false`, the OAuth tokens created to access the
github API will have a date instead of the machine hostname.

## gitlab-domains

Defaults to `["gitlab.com"]`. A list of domains of GitLab servers.
This is used if you use the `gitlab` repository type.

## notify-on-install

Defaults to `true`. Composer allows repositories to define a notification URL,
so that they get notified whenever a package from that repository is installed.
This option allows you to disable that behaviour.

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

&larr; [Repositories](05-repositories.md)  |  [Community](07-community.md) &rarr;
