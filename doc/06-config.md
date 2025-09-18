# Config

This chapter will describe the `config` section of the `composer.json`
[schema](04-schema.md).

## process-timeout

The timeout in seconds for process executions, defaults to 300 (5mins).
The duration processes like `git clone`s can run before
Composer assumes they died out. You may need to make this higher if you have a
slow connection or huge vendors.

Example:

```json
{
    "config": {
        "process-timeout": 900
    }
}
```

### Disabling timeouts for an individual script command

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

## allow-plugins

Defaults to `{}` which does not allow any plugins to be loaded.

As of Composer 2.2.0, the `allow-plugins` option adds a layer of security
allowing you to restrict which Composer plugins are able to execute code during
a Composer run.

When a new plugin is first activated, which is not yet listed in the config option,
Composer will print a warning. If you run Composer interactively it will
prompt you to decide if you want to execute the plugin or not.

Use this setting to allow only packages you trust to execute code. Set it to
an object with package name patterns as keys. The values are **true** to allow
and **false** to disallow while suppressing further warnings and prompts.

```json
{
    "config": {
        "allow-plugins": {
            "third-party/required-plugin": true,
            "my-organization/*": true,
            "unnecessary/plugin": false
        }
    }
}
```

You can also set the config option itself to `false` to disallow all plugins, or `true` to allow all plugins to run (NOT recommended). For example:

```json
{
    "config": {
        "allow-plugins": false
    }
}
```

## use-include-path

Defaults to `false`. If `true`, the Composer autoloader will also look for classes
in the PHP include path.

## preferred-install

Defaults to `dist` and can be any of `source`, `dist` or `auto`. This option
allows you to set the install method Composer will prefer to use. Can
optionally be an object with package name patterns for keys for more granular install preferences.

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

- `source` means Composer will install packages from their `source` if there
  is one. This is typically a git clone or equivalent checkout of the version
  control system the package uses. This is useful if you want to make a bugfix
  to a project and get a local git clone of the dependency directly.
- `auto` is the legacy behavior where Composer uses `source` automatically
  for dev versions, and `dist` otherwise.
- `dist` (the default as of Composer 2.1) means Composer installs from `dist`,
  where possible. This is typically a zip file download, which is faster than
  cloning the entire repository.

> **Note:** Order matters. More specific patterns should be earlier than
> more relaxed patterns. When mixing the string notation with the hash
> configuration in global and package configurations the string notation
> is translated to a `*` package pattern.

## audit

Security audit configuration options

### ignore

A list of advisory ids, remote ids or CVE ids that are reported but let the audit command pass.

```json
{
    "config": {
        "audit": {
            "ignore": {
                "CVE-1234": "The affected component is not in use.",
                "GHSA-xx": "The security fix was applied as a patch.",
                "PKSA-yy": "Due to mitigations in place the update can be delayed."
            }
        }
    }
}
```

or

```json
{
    "config": {
        "audit": {
            "ignore": ["CVE-1234", "GHSA-xx", "PKSA-yy"]
        }
    }
}
```

### abandoned

Defaults to `report` in Composer 2.6, and defaults to `fail` from Composer 2.7 on. Defines whether the audit command reports abandoned packages or not, this has three possible values:

- `ignore` means the audit command does not consider abandoned packages at all.
- `report` means abandoned packages are reported as an error but do not cause the command to exit with a non-zero code.
- `fail` means abandoned packages will cause audits to fail with a non-zero code.

```json
{
    "config": {
        "audit": {
            "abandoned": "report"
        }
    }
}
```

Since Composer 2.7, the option can be overridden via the [`COMPOSER_AUDIT_ABANDONED`](03-cli.md#composer-audit-abandoned) environment variable.

Since Composer 2.8, the option can be overridden via the
[`--abandoned`](03-cli.md#audit) command line option, which overrides both the
config value and the environment variable.


## use-parent-dir

When running Composer in a directory where there is no composer.json, if there
is one present in a directory above Composer will by default ask you whether
you want to use that directory's composer.json instead.

If you always want to answer yes to this prompt, you can set this config value
to `true`. To never be prompted, set it to `false`. The default is `"prompt"`.

> **Note:** This config must be set in your global user-wide config for it
> to work. Use for example `php composer.phar config --global use-parent-dir true`
> to set it.

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
of their API. Composer may prompt for credentials when needed, but these can also be
manually set. Read more on how to get an OAuth token for GitHub and cli syntax
[here](articles/authentication-for-private-packages.md#github-oauth).

## gitlab-domains

Defaults to `["gitlab.com"]`. A list of domains of GitLab servers.
This is used if you use the `gitlab` repository type.

## gitlab-oauth

A list of domain names and oauth keys. For example using `{"gitlab.com":
"oauthtoken"}` as the value of this option will use `oauthtoken` to access
private repositories on gitlab. Please note: If the package is not hosted at
gitlab.com the domain names must be also specified with the
[`gitlab-domains`](06-config.md#gitlab-domains) option.
Further info can also be found [here](articles/authentication-for-private-packages.md#gitlab-oauth)

## gitlab-token

A list of domain names and private tokens. Private token can be either simple
string, or array with username and token. For example using `{"gitlab.com":
"privatetoken"}` as the value of this option will use `privatetoken` to access
private repositories on gitlab. Using `{"gitlab.com": {"username": "gitlabuser",
 "token": "privatetoken"}}` will use both username and token for gitlab deploy
token functionality (https://docs.gitlab.com/ee/user/project/deploy_tokens/)
Please note: If the package is not hosted at
gitlab.com the domain names must be also specified with the
[`gitlab-domains`](06-config.md#gitlab-domains) option. The token must have
`api` or `read_api` scope.
Further info can also be found [here](articles/authentication-for-private-packages.md#gitlab-token)

## gitlab-protocol

A protocol to force use of when creating a repository URL for the `source`
value of the package metadata. One of `git` or `http`. (`https` is treated
as a synonym for `http`.) Helpful when working with projects referencing
private repositories which will later be cloned in GitLab CI jobs with a
[GitLab CI_JOB_TOKEN](https://docs.gitlab.com/ee/ci/variables/predefined_variables.html#predefined-variables-reference)
using HTTP basic auth. By default, Composer will generate a git-over-SSH
URL for private repositories and HTTP(S) only for public.

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
{"consumer-key": "myKey", "consumer-secret": "mySecret"}}`.
Read more [here](articles/authentication-for-private-packages.md#bitbucket-oauth).

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
More info can be found [here](articles/authentication-for-private-packages.md#http-basic).

## bearer

A list of domain names and tokens to authenticate against them. For example using
`{"example.org": "foo"}` as the value of this option will let Composer authenticate
against example.org using an `Authorization: Bearer foo` header.

## platform

Lets you fake platform packages (PHP and extensions) so that you can emulate a
production env or define your target platform in the config. Example: `{"php":
"7.0.3", "ext-something": "4.0.3"}`.

This will make sure that no package requiring more than PHP 7.0.3 can be installed
regardless of the actual PHP version you run locally. However it also means
the dependencies are not checked correctly anymore, if you run PHP 5.6 it will
install fine as it assumes 7.0.3, but then it will fail at runtime. This also means if
`{"php":"7.4"}` is specified; no packages will be used that define `7.4.1` as minimum.

Therefore if you use this it is recommended, and safer, to also run the
[`check-platform-reqs`](03-cli.md#check-platform-reqs) command as part of your
deployment strategy.

If a dependency requires some extension that you do not have installed locally
you may ignore it instead by passing `--ignore-platform-req=ext-foo` to `update`,
`install` or `require`. In the long run though you should install required
extensions as if you ignore one now and a new package you add a month later also
requires it, you may introduce issues in production unknowingly.

If you have an extension installed locally but *not* on production, you may want
to artificially hide it from Composer using `{"ext-foo": false}`.

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
Specifications, and `$COMPOSER_HOME` on other unix systems. Right now it is only
used for storing past composer.phar files to be able to roll back to older
versions. See also [COMPOSER_HOME](03-cli.md#composer-home).

## cache-dir

Defaults to `C:\Users\<user>\AppData\Local\Composer` on Windows,
`/Users/<user>/Library/Caches/composer` on macOS, `$XDG_CACHE_HOME/composer`
on unix systems that follow the XDG Base Directory Specifications, and
`$COMPOSER_HOME/cache` on other unix systems. Stores all the caches used by
Composer. See also [COMPOSER_HOME](03-cli.md#composer-home).

## cache-files-dir

Defaults to `$cache-dir/files`. Stores the zip archives of packages.

## cache-repo-dir

Defaults to `$cache-dir/repo`. Stores repository metadata for the `composer`
type and the VCS repos of type `svn`, `fossil`, `github` and `bitbucket`.

## cache-vcs-dir

Defaults to `$cache-dir/vcs`. Stores VCS clones for loading VCS repository
metadata for the `git`/`hg` types and to speed up installs.

## cache-files-ttl

Defaults to `15552000` (6 months). Composer caches all dist (zip, tar, ...)
packages that it downloads. Those are purged after six months of being unused by
default. This option allows you to tweak this duration (in seconds) or disable
it completely by setting it to 0.

## cache-files-maxsize

Defaults to `300MiB`. Composer caches all dist (zip, tar, ...) packages that it
downloads. When the garbage collection is periodically ran, this is the maximum
size the cache will be able to use. Older (less used) files will be removed
first until the cache fits.

## cache-read-only

Defaults to `false`. Whether to use the Composer cache in read-only mode.

## bin-compat

Defaults to `auto`. Determines the compatibility of the binaries to be installed.
If it is `auto` then Composer only installs .bat proxy files when on Windows or WSL. If
set to `full` then both .bat files for Windows and scripts for Unix-based
operating systems will be installed for each binary. This is mainly useful if you
run Composer inside a linux VM but still want the `.bat` proxies available for use
in the Windows host OS. If set to `proxy` Composer will only create bash/Unix-style
proxy files and no .bat files even on Windows/WSL.

## prepend-autoloader

Defaults to `true`. If `false`, the Composer autoloader will not be prepended to
existing autoloaders. This is sometimes required to fix interoperability issues
with other autoloaders.

## autoloader-suffix

Defaults to `null`. When set to a non-empty string, this value will be used as a
suffix for the generated Composer autoloader. If set to `null`, the
`content-hash` value from the `composer.lock` file will be used if available;
otherwise, a random suffix will be generated.

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

Defaults to `tar`. Overrides the default format used by the archive command.

## archive-dir

Defaults to `.`. Default destination for archives created by the archive
command.

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
in the Composer home, cache, and data directories.

## lock

Defaults to `true`. If set to `false`, Composer will not create a `composer.lock`
file and will ignore it if one is present.

## platform-check

Defaults to `php-only` which only checks the PHP version. Set to `true` to also
check the presence of extension. If set to `false`, Composer will not create and
require a `platform_check.php` file as part of the autoloader bootstrap.

## secure-svn-domains

Defaults to `[]`. Lists domains which should be trusted/marked as using a secure
Subversion/SVN transport. By default svn:// protocol is seen as insecure and will
throw, but you can set this config option to `["example.org"]` to allow using svn
URLs on that hostname. This is a better/safer alternative to disabling `secure-http`
altogether.

## bump-after-update

Defaults to `false` and can be any of `true`, `false`, `"dev"` or `"no-dev"`. If
set to true, Composer will run the `bump` command after running the `update` command.
If set to `"dev"` or `"no-dev"` then only the corresponding dependencies will be bumped.

## allow-missing-requirements

Defaults to `false`. Ignores error during `install` if there are any missing
requirements - the lock file is not up to date with the latest changes in
`composer.json`.

## minimal-changes

Defaults to `false`. If set to true, Composer will only perform absolutely necessary
changes to transitive dependencies during update.
Can also be set via the `COMPOSER_MINIMAL_CHANGES=1` env var.

&larr; [Repositories](05-repositories.md)  |  [Runtime](07-runtime.md) &rarr;
