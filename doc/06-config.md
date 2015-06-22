# Config

This chapter will describe the `config` section of the `composer.json` schema.

### config <span>([root-only](04-schema.md#root-package))</span>

A set of configuration options. It is only used for projects.

The following options are supported:

* **process-timeout:** Defaults to `300`. The duration processes like git clones
  can run before Composer assumes they died out. You may need to make this
  higher if you have a slow connection or huge vendors.
* **use-include-path:** Defaults to `false`. If true, the Composer autoloader
  will also look for classes in the PHP include path.
* **preferred-install:** Defaults to `auto` and can be any of `source`, `dist` or
  `auto`. This option allows you to set the install method Composer will prefer to
  use.
* **store-auths:** What to do after prompting for authentication, one of:
  `true` (always store), `false` (do not store) and `"prompt"` (ask every
  time), defaults to `"prompt"`.
* **github-protocols:** Defaults to `["git", "https", "ssh"]`. A list of protocols to
  use when cloning from github.com, in priority order. You can reconfigure it to
  for example prioritize the https protocol if you are behind a proxy or have somehow
  bad performances with the git protocol.
* **github-oauth:** A list of domain names and oauth keys. For example using
  `{"github.com": "oauthtoken"}` as the value of this option will use `oauthtoken`
  to access private repositories on github and to circumvent the low IP-based
  rate limiting of their API.
  [Read more](articles/troubleshooting.md#api-rate-limit-and-oauth-tokens)
  on how to get an OAuth token for GitHub.
* **http-basic:** A list of domain names and username/passwords to authenticate
  against them. For example using
  `{"example.org": {"username": "alice", "password": "foo"}` as the value of this
  option will let composer authenticate against example.org.
* **platform:** Lets you fake platform packages (PHP and extensions) so that
  you can emulate a production env or define your target platform in the
  config. e.g. `{"php": "5.4", "ext-something": "4.0"}`.
* **vendor-dir:** Defaults to `vendor`. You can install dependencies into a
  different directory if you want to. `$HOME` and `~` will be replaced by your
  home directory's path in vendor-dir and all `*-dir` options below.
* **bin-dir:** Defaults to `vendor/bin`. If a project includes binaries, they
  will be symlinked into this directory.
* **cache-dir:** Defaults to `$COMPOSER_HOME/cache` on unix systems and
  `C:\Users\<user>\AppData\Local\Composer` on Windows. Stores all the caches
  used by composer. See also [COMPOSER_HOME](03-cli.md#composer-home).
* **cache-files-dir:** Defaults to `$cache-dir/files`. Stores the zip archives
  of packages.
* **cache-repo-dir:** Defaults to `$cache-dir/repo`. Stores repository metadata
  for the `composer` type and the VCS repos of type `svn`, `github` and `bitbucket`.
* **cache-vcs-dir:** Defaults to `$cache-dir/vcs`. Stores VCS clones for
  loading VCS repository metadata for the `git`/`hg` types and to speed up installs.
* **cache-files-ttl:** Defaults to `15552000` (6 months). Composer caches all
  dist (zip, tar, ..) packages that it downloads. Those are purged after six
  months of being unused by default. This option allows you to tweak this
  duration (in seconds) or disable it completely by setting it to 0.
* **cache-files-maxsize:** Defaults to `300MiB`. Composer caches all
  dist (zip, tar, ..) packages that it downloads. When the garbage collection
  is periodically ran, this is the maximum size the cache will be able to use.
  Older (less used) files will be removed first until the cache fits.
* **prepend-autoloader:** Defaults to `true`. If false, the composer autoloader
  will not be prepended to existing autoloaders. This is sometimes required to fix
  interoperability issues with other autoloaders.
* **autoloader-suffix:** Defaults to `null`. String to be used as a suffix for
  the generated Composer autoloader. When null a random one will be generated.
* **optimize-autoloader** Defaults to `false`. Always optimize when dumping
  the autoloader.
* **classmap-authoritative:** Defaults to `false`. If true, the composer
  autoloader will not scan the filesystem for classes that are not found in
  the class map. Implies 'optimize-autoloader'.
* **github-domains:** Defaults to `["github.com"]`. A list of domains to use in
  github mode. This is used for GitHub Enterprise setups.
* **github-expose-hostname:** Defaults to `true`. If set to false, the OAuth
  tokens created to access the github API will have a date instead of the
  machine hostname.
* **notify-on-install:** Defaults to `true`. Composer allows repositories to
  define a notification URL, so that they get notified whenever a package from
  that repository is installed. This option allows you to disable that behaviour.
* **discard-changes:** Defaults to `false` and can be any of `true`, `false` or
  `"stash"`. This option allows you to set the default style of handling dirty
  updates when in non-interactive mode. `true` will always discard changes in
  vendors, while `"stash"` will try to stash and reapply. Use this for CI
  servers or deploy scripts if you tend to have modified vendors.
* **archive-format:** Defaults to `tar`. Composer allows you to add a default
  archive format when the workflow needs to create a dedicated archiving format.
* **archive-dir:** Defaults to `.`. Composer allows you to add a default
  archive directory when the workflow needs to create a dedicated archiving format.
  Or for easier development between modules.

Example:

```json
{
    "config": {
        "bin-dir": "bin"
    }
}
```

> **Note:** Authentication-related config options like `http-basic` and
> `github-oauth` can also be specified inside a `auth.json` file that goes
> besides your `composer.json`. That way you can gitignore it and every
> developer can place their own credentials in there.

### scripts <span>(root-only)</span>

Composer allows you to hook into various parts of the installation process
through the use of scripts.

See [Scripts](articles/scripts.md) for events details and examples.

### extra

Arbitrary extra data for consumption by `scripts`.

This can be virtually anything. To access it from within a script event
handler, you can do:

```php
$extra = $event->getComposer()->getPackage()->getExtra();
```

Optional.

### bin

A set of files that should be treated as binaries and symlinked into the `bin-dir`
(from config).

See [Vendor Binaries](articles/vendor-binaries.md) for more details.

Optional.

### archive

A set of options for creating package archives.

The following options are supported:

* **exclude:** Allows configuring a list of patterns for excluded paths. The
  pattern syntax matches .gitignore files. A leading exclamation mark (!) will
  result in any matching files to be included even if a previous pattern
  excluded them. A leading slash will only match at the beginning of the project
  relative path. An asterisk will not expand to a directory separator.

Example:

```json
{
    "archive": {
        "exclude": ["/foo/bar", "baz", "/*.test", "!/foo/bar/baz"]
    }
}
```

The example will include `/dir/foo/bar/file`, `/foo/bar/baz`, `/file.php`,
`/foo/my.test` but it will exclude `/foo/bar/any`, `/foo/baz`, and `/my.test`.

Optional.

### non-feature-branches

A list of regex patterns of branch names that are non-numeric (e.g. "latest" or something), that will NOT be handled as feature branches. This is an array of strings.

If you have non-numeric branch names, for example like "latest", "current", "latest-stable"
or something, that do not look like a version number, then composer handles such branches
as feature branches. This means it searches for parent branches, that look like a version
or ends at special branches (like master) and the root package version number becomes the
version of the parent branch or at least master or something.

To handle non-numeric named branches as versions instead of searching for a parent branch
with a valid version or special branch name like master, you can set patterns for branch
names, that should be handled as dev version branches.

This is really helpful when you have dependencies using "self.version", so that not dev-master,
but the same branch is installed (in the example: latest-testing).

An example:

If you have a testing branch, that is heavily maintained during a testing phase and is
deployed to your staging environment, normally "composer show -s" will give you `versions : * dev-master`.

If you configure `latest-.*` as a pattern for non-feature-branches like this:

```json
{
    "non-feature-branches": ["latest-.*"]
}
```

Then "composer show -s" will give you `versions : * dev-latest-testing`.

Optional.

&larr; [Repositories](05-repositories.md)  |  [Community](07-community.md) &rarr;
