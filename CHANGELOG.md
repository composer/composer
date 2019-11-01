### [1.9.1] 2019-11-01

  * Fixed various credential handling issues with gitlab and github
  * Fixed credentials being present in git remotes in Composer cache and vendor directory when not using SSH keys
  * Fixed `composer why` not listing replacers as a reason something is present
  * Fixed various PHP 7.4 compatibility issues
  * Fixed root warnings always present in Docker containers, setting COMPOSER_ALLOW_SUPERUSER is not necessary anymore
  * Fixed GitHub access tokens leaking into debug-verbosity output
  * Fixed several edge case issues detecting GitHub, Bitbucket and GitLab repository types
  * Fixed Composer asking if you want to use a composer.json in a parent directory when ran in non-interactive mode
  * Fixed classmap autoloading issue finding classes located within a few non-PHP context blocks (?>...<?php)

### [1.9.0] 2019-08-02

  * Breaking: artifact repositories with URLs containing port numbers and requiring authentication now require you to configure http-basic auth for the `host:port` pair explicitly
  * Added a `--no-cache` flag available on all commands to run with the cache disabled
  * Added PHP_BINARY as env var pointing to the PHP process when executing Composer scripts as shell scripts
  * Added a `use-github-api` config option which can set the `no-api` flag on all GitHub VCS repositories declared
  * Added a static helper you can preprend to a script to avoid process timeouts, `"Composer\\Config::disableProcessTimeout"`
  * Added Event::getOriginatingEvent to retrieve an event's original event when a script handler forwards to another one
  * Added support for autoloading directly from a phar file
  * Fixed loading order of plugins to always initialize them in order of dependencies
  * Fixed various network-mount related issues
  * Fixed --ignore-platform-reqs not ignoring conflict rules against platform packages

### [1.8.6] 2019-06-11

  * Fixed handling of backslash-escapes handling in composer.json when using the require command
  * Fixed create-project not following classmap-authoritative and apcu-autoloader config values
  * Fixed HHVM version warning showing up in some cases when it was not in use

### [1.8.5] 2019-04-09

  * HHVM 4.0 is no longer compatible with Composer. Please use PHP instead going forward.
  * Added forward compatibility with upcoming 2.0 changes
  * Fixed support for PHP 7.3-style heredoc/nowdoc syntax changes in autoload generation
  * Fixed require command usage when combined with --ignore-platform-reqs
  * Fixed and cleaned up various Windows junctions handling issues

### [1.8.4] 2019-02-11

  * Fixed long standing solver bug leading to odd solving issues in edge cases, see #7946
  * Fixed HHVM support for upcoming releases
  * Fixed unix proxy for binaries to be POSIX compatible instead of breaking some shells
  * Fixed invalid deprecation warning for composer-plugin-api
  * Fixed edge case issues with Windows junctions when working with path repositories

### [1.8.3] 2019-01-30

  * Fixed regression when executing partial updates

### [1.8.2] 2019-01-29

  * Fixed invalid deprecation warning for ext-pdo_mysql and similar
  * Updated to latest xdebug-handler

### [1.8.1] 2019-01-29

  * Deprecated support for non-standard package names (anything with uppercase, or no / in it). Make sure to follow the warnings if you see any to avoid problems in 2.0.
  * Fixed some packages missing from the autoloader config when installing with --no-dev
  * Fixed support for cloning GitLab repos using OAuth tokens instead of SSH keys
  * Fixed metapackage installs/updates missing from output
  * Fixed --with-dependencies / --with-all-dependencies not updating some packages in some edge cases
  * Fixed compatibility with Symfony 4.2 deprecations
  * Fixed temp dir not being cleaned up on download error while archiving packages
  * Updated to latest ca-bundle

### [1.8.0] 2018-12-03

  * Changed `post-package-install` / `post-package-update` event to be fired *after* the lock file has been updated as opposed to before
  * Added support for removing packages using a wildcard with the `remove` command, e.g. `composer remove foo/*`
  * Added `chat` to the list of `support` channels you can list in composer.json
  * Added signal handling on require command to restore the composer.json in case of abort
  * Added `--ignore` to `outdated` command to pass one or more packages that you do not want to be listed
  * Added `--no-dev` to `check-platform-reqs` command to skip dev requirements even if they are installed
  * Added support for running plugin commands from sub-directories within a project much like other Composer commands
  * Added support for running Composer via phpdbg
  * Added `lib-imagick` platform package
  * Fixed validate command always checking for disabled checks when used with `--strict`

### [1.7.3] 2018-11-01

  * Fixed handling of replace/conflict rules. This may affect dependency resolution in some edge cases.
  * Fixed Bitbucket API support and migrated all calls to API v2 as v1 is deprecated
  * Fixed support for lib-openssl 1.1.1 having only lowercase algorithm names
  * Fixed escaping of URLs in Perforce and Svn drivers
  * Fixed `show` command not respecting `--path` when a single package name was given
  * Fixed regression in 1.7.2's handling of metapackages

### [1.7.2] 2018-08-16

  * Fixed reporting of authentication/rate limiting issues for GitHub API access
  * Fixed `create-project` not checking the checking the latest commit out when a cache was already present
  * Fixed reporting of errors when `global` command can not switch the working directory
  * Fixed PHP 5.3 JSON encoding issues with complex unicode character sequences
  * Updated to latest ca-bundle and xdebug-handler projects, see related changelogs

### [1.7.1] 2018-08-07

  * Fixed issue autoloading plugins in require-dev in some conditions
  * Fixed handling of SSL to repo.packagist.org on very old PHP versions

### [1.7.0] 2018-08-03

  * Added the overridden platform config's PHP version in the `diagnose` command output
  * Fixed --no-plugins not being respected in a few commands
  * Fixed 1.7.0-RC regression in output showing <warn> instead of proper colors
  * Fixed 1.7.0-RC regression in output missing "Loading from cache" output on package install

### [1.7.0-RC] 2018-07-24

  * Changed default repository URL from packagist.org to repo.packagist.org, this might affect people with strict firewall rules
  * Changed output from Updating to Downgrading when performing package downgrades, this might affect anything parsing output
  * Several minor performance improvements
  * Added basic authentication support for mercurial repos
  * Added explicit `i` and `u` aliases for the `install` and `update` commands
  * Added support for `show` command to output json format with --tree
  * Added support for {glob,braces} support in the path repository's path argument
  * Added support in `status` command for showing diffs in vendor dir even for packages installed as dist/zip archives
  * Added `--remove-vcs` flag to `create-project` command to avoid prompting for keeping VCS files
  * Added `--no-secure-http` flag to `create-project` command to bypass https (use at your own risk)
  * Added `pre-command-run` event that lets plugins modify arguments
  * Added RemoteFilesystem::getRemoteContents extension point
  * Fixed setting scripts via `config` command

### [1.6.5] 2018-05-04

  * Fixed regression in 1.6.4 causing strange update behaviors with dev packages
  * Fixed regression in 1.6.4 color support detection for Windows
  * Fixed issues dealing with broken symlinks when switching branches and using path repositories
  * Fixed JSON schema for package repositories
  * Fixed issues on computers set to Turkish locale
  * Fixed classmap parsing of files using short-open-tags when they are disabled in php

### [1.6.4] 2018-04-13

  * Security fixes in some edge case scenarios, recommended update for all users
  * Fixed regression in version guessing of path repositories
  * Fixed removing aliased packages from the repository, which might resolve some odd update bugs
  * Fixed updating of package URLs for GitLab
  * Fixed run-script --list failing when script handlers were defined
  * Fixed init command not respecting the current php version when selecting package versions
  * Fixed handling of uppercase package names in why/why-not commands
  * Fixed exclude-from-classmap symlink handling
  * Fixed filesystem permissions of PEAR binaries
  * Improved performance of subversion repos
  * Other minor fixes

### [1.6.3] 2018-01-31

  * Fixed GitLab downloads failing in some edge cases
  * Fixed ctrl-C handling during create-project
  * Fixed GitHub VCS repositories not prompting for a token in some conditions
  * Fixed SPDX license identifiers being case sensitive
  * Fixed and clarified a few dependency resolution error reporting strings
  * Fixed SVN commit log fetching in verbose mode when using private repositories

### [1.6.2] 2018-01-05

  * Fixed more autoloader regressions
  * Fixed support for updating dist refs in gitlab URLs

### [1.6.1] 2018-01-04

  * Fixed upgrade regression due to some autoloader cleanups
  * Fixed some overly loose version constraints

### [1.6.0] 2018-01-04

  * Added support for SPDX license identifiers v3.0, deprecates GPL/LGPL/AGPL identifiers, which should now have a `-only` or `-or-later` suffix added.
  * Added support for COMPOSER_MEMORY_LIMIT env var to make Composer set the PHP memory limit explicitly
  * Added support for simple strings for the `bin`
  * Fixed `check-platform-reqs` bug in version checking

### [1.6.0-RC] 2017-12-19

  * Improved performance of installs and updates from git clones when checking out known commits
  * Added `check-platform-reqs` command that checks that your PHP and extensions versions match the platform requirements of the installed packages
  * Added `--with-all-dependencies` to the `update` and `require` commands which updates all dependencies of the listed packages, including those that are direct root requirements
  * Added `scripts-descriptions` key to composer.json to customize the description and document your custom commands
  * Added support for the uppercase NO_PROXY env var
  * Added support for COMPOSER_DEFAULT_{AUTHOR,LICENSE,EMAIL,VENDOR} env vars to pre-populate init command values
  * Added support for local fossil repositories
  * Added suggestions for alternative spellings when entering packages in `init` and `require` commands and nothing can be found
  * Fixed installed.json data to be sorted alphabetically by package name
  * Fixed compatibility with Symfony 4.x components that Composer uses

### [1.5.6] - 2017-12-18

  * Fixed root package version guessed when a tag is checked out
  * Fixed support for GitLab repos hosted on non-standard ports
  * Fixed regression in require command when requiring unstable packages, part 3

### [1.5.5] - 2017-12-01

  * Fixed regression in require command when requiring unstable packages, part 2

### [1.5.4] - 2017-12-01

  * Fixed regression in require command when requiring unstable packages

### [1.5.3] - 2017-11-30

  * Fixed require/remove commands reverting the composer.json change when a non-solver-related error occurs
  * Fixed GitLabDriver to support installations of GitLab not at the root of the domain
  * Fixed create-project not following the optimize-autoloader flag of the root package
  * Fixed Authorization header being forwarded across domains after a redirect
  * Improved some error messages for clarity

### [1.5.2] - 2017-09-11

  * Fixed GitLabDriver looping endlessly in some conditions
  * Fixed GitLabDriver support for unauthenticated requests
  * Fixed GitLab zip downloads not triggering credentials prompt if unauthenticated
  * Fixed path repository support of COMPOSER_ROOT_VERSION, it now applies to all path repos within the same git repository
  * Fixed path repository handling of copies to avoid copying VCS files and others
  * Fixed sub-directory call to ignore list and create-project commands as well as calls to Composer using --working-dir
  * Fixed invalid warning appearing when calling `remove` on an non-stable package

### [1.5.1] - 2017-08-09

  * Fixed regression in GitLabDriver with repos containing >100 branches or tags
  * Fixed sub-directory call support to respect the COMPOSER env var

### [1.5.0] - 2017-08-08

  * Changed the package install order to ensure that plugins are always installed as soon as possible
  * Added ability to call composer from within sub-directories of a project
  * Added support for GitLab API v4
  * Added support for GitLab sub-groups
  * Added some more rules to composer validate
  * Added support for reading the `USER` env when guessing the username in `composer init`
  * Added warning when uncompressing files with the same name but difference cases on case insensitive filesystems
  * Added `htaccess-protect` option / `COMPOSER_HTACCESS_PROTECT` env var to disable the .htaccess creation in home dir (defaults to true)
  * Improved `clear-cache` command
  * Minor improvements/fixes and many documentation updates

### [1.4.3] - 2017-08-06

  * Fixed GitLab URLs
  * Fixed root package version detection using latest git versions
  * Fixed inconsistencies in date format in composer.lock when installing from source
  * Fixed Mercurial support regression
  * Fixed exclude-from-classmap not being applied when autoloading files for Composer plugins
  * Fixed exclude-from-classmap being ignored when cwd has the wrong case on case insensitive filesystems
  * Fixed several other minor issues

### [1.4.2] - 2017-05-17

  * Fixed Bitbucket API handler parsing old deleted branches in hg repos
  * Fixed regression in gitlab downloads
  * Fixed output inconsistencies
  * Fixed unicode handling in `init` command for author names
  * Fixed useless warning when doing partial updates/removes on packages that are not currently installed
  * Fixed xdebug disabling issue when combined with disable_functions and allow_url_fopen CLI overrides

### [1.4.1] - 2017-03-10

  * Fixed `apcu-autoloader` config option being ignored in `dump-autoload` command
  * Fixed json validation not allowing boolean for trunk-path, branches-path and tags-path in svn repos
  * Fixed json validation not allowing repository URLs without scheme

### [1.4.0] - 2017-03-08

  * Improved memory usage of dependency solver
  * Added `--format json` option to the `outdated` and `show` command to get machine readable package listings
  * Added `--ignore-filters` flag to `archive` command to bypass the .gitignore and co
  * Added support for `outdated` output without ansi colors
  * Added support for Bitbucket API v2
  * Changed the require command to follow minimum-stability / prefer-stable values when picking a version
  * Fixed regression when using composer in a Mercurial repository

### [1.3.3] - 2017-03-08

  * **Capifony users beware**: This release has output format tweaks that mess up capifony interactive mode, see #6233
  * Improved baseline psr-4 autoloader performance for projects with many nested namespaces configured
  * Fixed issues with gitlab API access when the token had insufficient permissions
  * Fixed some HHVM strict type issues
  * Fixed version guessing of headless git checkouts in some conditions
  * Fixed compatibility with subversion 1.8
  * Fixed version guessing not working with svn/hg
  * Fixed script/exec errors not being output correctly
  * Fixed PEAR repository bug with pear.php.net

### [1.3.2] - 2017-01-27

  * Added `COMPOSER_BINARY` env var that is defined within the scope of a Composer run automatically with the path to the phar file
  * Fixed create-project ending in a detached HEAD when installing aliased packages
  * Fixed composer show not returning non-zero exit code when the package does not exist
  * Fixed `@composer` handling in scripts when --working-dir is used together with it
  * Fixed private-GitLab handling of repos with dashes in them

### [1.3.1] - 2017-01-07

  * Fixed dist downloads from Bitbucket
  * Fixed some regressions related to xdebug disabling
  * Fixed `--minor-only` flag in `outdated` command
  * Fixed handling of config.platform.php which did not replace other php-* package's versions

### [1.3.0] - 2016-12-24

  * Fixed handling of annotated git tags vs lightweight tags leading to useless updates sometimes
  * Fixed ext-xdebug not being require-able anymore due to automatic xdebug disabling
  * Fixed case insensitivity of remove command

### [1.3.0-RC] - 2016-12-11

  * Added workaround for xdebug performance impact by restarting PHP without xdebug automatically in case it is enabled
  * Added `--minor-only` to the `outdated` command to only show updates to minor versions and ignore new major versions
  * Added `--apcu-autoloader` to the `update`/`install` commands and `--apcu` to `dump-autoload` to enable an APCu-caching autoloader, which can be more efficient than --classmap-authoritative if you attempt to autoload many classes that do not exist, or if you can not use authoritative classmaps for some reason
  * Added summary of operations to be executed before they run, and made execution output more compact
  * Added `php-debug` and `php-zts` virtual platform packages
  * Added `gitlab-token` auth config for GitLab private tokens
  * Added `--strict` to the `outdated` command to return a non-zero exit code when there are outdated packages
  * Added ability to call php scripts using the current php interpreter (instead of finding php in PATH by default) in script handlers via `@php ...`
  * Added `COMPOSER_ALLOW_XDEBUG` env var to circumvent the xdebug-disabling behavior
  * Added `COMPOSER_MIRROR_PATH_REPOS` env var to force mirroring of path repositories vs symlinking
  * Added `COMPOSER_DEV_MODE` env var that is set by Composer to forward the dev mode to script handlers
  * Fixed support for git 2.11
  * Fixed output from zip and rar leaking out when an error occurred
  * Removed `hash` from composer.lock, only `content-hash` is now used which should reduce conflicts
  * Minor fixes and performance improvements

### [1.2.4] - 2016-12-06

  * Fixed regression in output handling of scripts from 1.2.3
  * Fixed support for LibreSSL detection as lib-openssl
  * Fixed issue with Zend Guard in the autoloader bootstrapping
  * Fixed support for loading partial provider repositories

### [1.2.3] - 2016-12-01

  * Fixed bug in HgDriver failing to identify BitBucket repositories
  * Fixed support for loading partial provider repositories

### [1.2.2] - 2016-11-03

  * Fixed selection of packages based on stability to be independent from package repository order
  * Fixed POST_DEPENDENCIES_SOLVING not containing some operations in edge cases
  * Fixed issue handling GitLab URLs containing dots and other special characters
  * Fixed issue on Windows when running composer at the root of a drive
  * Minor fixes

### [1.2.1] - 2016-09-12

  * Fixed edge case issues with the static autoloader
  * Minor fixes

### [1.2.0] - 2016-07-19

  * Security: Fixed [httpoxy](https://httpoxy.org/) vulnerability
  * Fixed `home` command to avoid rogue output on unix
  * Fixed output of git clones to clearly state when clones are from cache
  * (from 1.2 RC) Fixed ext-network-ipv6 to be php-ipv6

### [1.2.0-RC] - 2016-07-04

  * Added caching of git repositories if you have git 2.3+ installed. Repositories will now be cached once and then cloned from local cache so subsequent installs should be faster
  * Added detection of HEAD changes to the `status` command. If you `git checkout X` in a vendor directory for example it will tell you that it is not at the version that was installed
  * Added a virtual `php-ipv6` extension to require PHP compiled with IPv6 support
  * Added `--no-suggest` to `install` and `update` commands to skip output of suggestions at the end
  * Added `--type` to the `search` command to restrict to a given package type
  * Added fossil support as alternative to git/svn/.. for package downloads
  * Improved BitBucket OAuth support
  * Added support for blocking cache operations using COMPOSER_CACHE_DIR=/dev/null (or NUL on windows)
  * Added support for using declare(strict_types=1) in plugins
  * Added `--prefer-stable` and `--prefer-lowest` to the `require` command
  * Added `--no-scripts` to the `require` and `remove` commands
  * Added `_comment` top level key to the schema to endorse using it as a place to store comments (it can be a string or array of strings)
  * Added support for justinrainbow/json-schema 2.0
  * Fixed binaries not being re-installed if deleted by users or the bin-dir changes. `update` and `install` will now re-install them
  * Many minor UX and docs improvements

### [1.1.3] - 2016-06-26

  * Fixed bitbucket oauth instructions
  * Fixed version parsing issue
  * Fixed handling of bad proxies that modify JSON content on the fly

### [1.1.2] - 2016-05-31

  * Fixed degraded mode issue when accessing packagist.org
  * Fixed GitHub access_token being added on subsequent requests in case of redirections
  * Fixed exclude-from-classmap not working in some circumstances
  * Fixed openssl warning preventing the use of config command for disabling tls

### [1.1.1] - 2016-05-17

  * Fixed regression in handling of #reference which made it update every time
  * Fixed dev platform requirements being required even in --no-dev install from a lock file
  * Fixed parsing of extension versions that do not follow valid numbers, we now try to parse x.y.z and ignore the rest
  * Fixed exact constraints warnings appearing for 0.x versions
  * Fixed regression in the `remove` command

### [1.1.0] - 2016-05-10

  * Added fallback to SSH for https bitbucket URLs
  * Added BaseCommand::isProxyCommand that can be overridden to mark a command as being a mere proxy, which helps avoid duplicate warnings etc on composer startup
  * Fixed archiving generating long paths in zip files on Windows

### [1.1.0-RC] - 2016-04-29

  * Added ability for plugins to register their own composer commands
  * Optimized the autoloader initialization using static loading on PHP 5.6 and above, this reduces the load time for large classmaps to almost nothing
  * Added `--latest` to `show` command to show the latest version available of your dependencies
  * Added `--outdated` to `show` command an `composer outdated` alias for it, to show only packages in need of update
  * Added `--direct` to `show` and `outdated` commands to show only your direct dependencies in the listing
  * Added support for editing all top-level properties (name, minimum-stability, ...) as well as extra values via the `config` command
  * Added abandoned state warning to the `show` and `outdated` commands when listing latest packages
  * Added support for `~/` and `$HOME/` in the path repository paths
  * Added support for wildcards in the `show` command package filter, e.g. `composer show seld/*`
  * Added ability to call composer itself from scripts via `@composer ...`
  * Added untracked files detection to the `status` command
  * Added warning to `validate` command when using exact-version requires
  * Added warning once per domain when accessing insecure URLs with secure-http disabled
  * Added a dependency on composer/ca-bundle (extracted CA bundle management to a standalone lib)
  * Added support for empty directories when archiving to tar
  * Added an `init` event for plugins to react to, which occurs right after a Composer instance is fully initialized
  * Added many new detections of problems in the `why-not`/`prohibits` command to figure out why something does not get installed in the expected version
  * Added a deprecation notice for script event listeners that use legacy script classes
  * Fixed abandoned state not showing up if you had a package installed before it was marked abandoned
  * Fixed --no-dev updates creating an incomplete lock file, everything is now always resolved on update
  * Fixed partial updates in case the vendor dir was not up to date with the lock file

### [1.0.3] - 2016-04-29

  * Security: Fixed possible command injection from the env vars into our sudo detection
  * Fixed interactive authentication with gitlab
  * Fixed class name replacement in plugins
  * Fixed classmap generation mistakenly detecting anonymous classes
  * Fixed auto-detection of stability flags in complex constraints like `2.0-dev || ^1.5`
  * Fixed content-length handling when redirecting to very small responses

### [1.0.2] - 2016-04-21

  * Fixed regression in 1.0.1 on systems with mbstring.func_overload enabled
  * Fixed regression in 1.0.1 that made dev packages update to the latest reference even if not whitelisted in a partial update
  * Fixed init command ignoring the COMPOSER env var for choosing the json file name
  * Fixed error reporting bug when the dependency resolution fails
  * Fixed handling of `$` sign in composer config command in some cases it could corrupt the json file

### [1.0.1] - 2016-04-18

  * Fixed URL updating when a package's URL changes, composer.lock now contains the right URL including correct reference
  * Fixed URL updating of the origin git remote as well for packages installed as git clone
  * Fixed binary .bat files generated from linux being incompatible with windows cmd
  * Fixed handling of paths with trailing slashes in path repository
  * Fixed create-project not using platform config when selecting a package
  * Fixed self-update not showing the channel it uses to perform the update
  * Fixed file downloads not failing loudly when the content does not match the Content-Length header
  * Fixed secure-http detecting some malformed URLs as insecure
  * Updated CA bundle

### [1.0.0] - 2016-04-05

  * Added support for bitbucket-oauth configuration
  * Added warning when running composer as super user, set COMPOSER_ALLOW_SUPERUSER=1 to hide the warning if you really must
  * Added PluginManager::getGlobalComposer getter to retrieve the global instance (which can be null!)
  * Fixed dependency solver error reporting in many cases it now shows you proper errors instead of just saying a package does not exist
  * Fixed output of failed downloads appearing as 100% done instead of Failed
  * Fixed handling of empty directories when archiving, they are not skipped anymore
  * Fixed installation of broken plugins corrupting the vendor state when combined with symlinked path repositories

### [1.0.0-beta2] - 2016-03-27

  * Break: The `install` command now turns into an `update` command automatically if you have no composer.lock. This was done only half-way before which caused inconsistencies
  * Break: By default the `remove` command now removes dependencies as well, and --update-with-dependencies is deprecated. Use --no-update-with-dependencies to get old behavior
  * Added support for update channels in `self-update`. All users will now update to stable builds by default. Run `self-update` with `--snapshot`, `--preview` or `--stable` to switch between update channels.
  * Added support for SSL_CERT_DIR env var and openssl.capath ini value
  * Added some conflict detection in `why-not` command
  * Added suggestion of root package's suggests in `create-project` command
  * Fixed `create-project` ignoring --ignore-platform-reqs when choosing a version of the package
  * Fixed `search` command in a directory without composer.json
  * Fixed path repository handling of symlinks on windows
  * Fixed PEAR repo handling to prefer HTTPS mirrors over HTTP ones
  * Fixed handling of Path env var on Windows, only PATH was accepted before
  * Small error reporting and docs improvements

### [1.0.0-beta1] - 2016-03-03

  * Break: By default we now disable any non-secure protocols (http, git, svn). This may lead to issues if you rely on those. See `secure-http` config option.
  * Break: `show` / `list` command now only show installed packages by default. An `--all` option is added to show all packages.
  * Added VCS repo support for the GitLab API, see also `gitlab-oauth` and `gitlab-domains` config options
  * Added `prohibits` / `why-not` command to show what blocks an upgrade to a given package:version pair
  * Added --tree / -t to the `show` command to see all your installed packages in a tree view
  * Added --interactive / -i to the `update` command, which lets you pick packages to update interactively
  * Added `exec` command to run binaries while having bin-dir in the PATH for convenience
  * Added --root-reqs to the `update` command to update only your direct, first degree dependencies
  * Added `cafile` and `capath` config options to control HTTPS certificate authority
  * Added pubkey verification of composer.phar when running self-update
  * Added possibility to configure per-package `preferred-install` types for more flexibility between prefer-source and prefer-dist
  * Added unpushed-changes detection when updating dependencies and in the `status` command
  * Added COMPOSER_AUTH env var that lets you pass a json configuration like the auth.json file
  * Added `secure-http` and `disable-tls` config options to control HTTPS/HTTP
  * Added warning when Xdebug is enabled as it reduces performance quite a bit, hide it with COMPOSER_DISABLE_XDEBUG_WARN=1 if you must
  * Added duplicate key detection when loading composer.json
  * Added `sort-packages` config option to force sorting of the requirements when using the `require` command
  * Added support for the XDG Base Directory spec on linux
  * Added XzDownloader for xz file support
  * Fixed SSL support to fully verify peers in all PHP versions, unsecure HTTP is also disabled by default
  * Fixed stashing and cleaning up of untracked files when updating packages
  * Fixed plugins being enabled after installation even when --no-plugins
  * Many small bug fixes and additions

### [1.0.0-alpha11] - 2015-11-14

  * Added config.platform to let you specify what your target environment looks like and make sure you do not inadvertently install dependencies that would break it
  * Added `exclude-from-classmap` in the autoload config that lets you ignore sub-paths of classmapped directories, or psr-0/4 directories when building optimized autoloaders
  * Added `path` repository type to install/symlink packages from local paths
  * Added possibility to reference script handlers from within other handlers using @script-name to reduce duplication
  * Added `suggests` command to show what packages are suggested, use -v to see more details
  * Added `content-hash` inside the composer.lock to restrict the warnings about outdated lock file to some specific changes in the composer.json file
  * Added `archive-format` and `archive-dir` config options to specify default values for the archive command
  * Added --classmap-authoritative to `install`, `update`, `require`, `remove` and `dump-autoload` commands, forcing the optimized classmap to be authoritative
  * Added -A / --with-dependencies to the `validate` command to allow validating all your dependencies recursively
  * Added --strict to the `validate` command to treat any warning as an error that then returns a non-zero exit code
  * Added a dependency on composer/semver, which is the externalized lib for all the version constraints parsing and handling
  * Added support for classmap autoloading to load plugin classes and script handlers
  * Added `bin-compat` config option that if set to `full` will create .bat proxy for binaries even if Composer runs in a linux VM
  * Added SPDX 2.0 support, and externalized that in a composer/spdx-licenses lib
  * Added warnings when the classmap autoloader finds duplicate classes
  * Added --file to the `archive` command to choose the filename
  * Added Ctrl+C handling in create-project to cancel the operation cleanly
  * Fixed version guessing to use ^ always, default to stable versions, and avoid versions that require a higher php version than you have
  * Fixed the lock file switching back and forth between old and new URL when a package URL is changed and many people run updates
  * Fixed partial updates updating things they shouldn't when the current vendor dir was out of date with the lock file
  * Fixed PHAR file creation to be more reproducible and always generate the exact same phar file from a given source
  * Fixed issue when checking out git branches or tags that are also the name of a file in the repo
  * Many minor fixes and documentation additions and UX improvements

### [1.0.0-alpha10] - 2015-04-14

  * Break: The following event classes are deprecated and you should update your script handlers to use the new ones in type hints:
    - `Composer\Script\CommandEvent` is deprecated, use `Composer\Script\Event`
    - `Composer\Script\PackageEvent` is deprecated, use `Composer\Installer\PackageEvent`
  * Break: Output is now split between stdout and stderr. Any irrelevant output to each command is on stderr as per unix best practices.
  * Added support for npm-style semver operators (`^` and `-` ranges, ` ` = AND, `||` = OR)
  * Added --prefer-lowest to `update` command to allow testing a package with the lowest declared dependencies
  * Added support for parsing semver build metadata `+anything` at the end of versions
  * Added --sort-packages option to `require` command for sorting dependencies
  * Added --no-autoloader to `install` and `update` commands to skip autoload generation
  * Added --list to `run-script` command to see available scripts
  * Added --absolute to `config` command to get back absolute paths
  * Added `classmap-authoritative` config option, if enabled only the classmap info will be used by the composer autoloader
  * Added support for branch-alias on numeric branches
  * Added support for the `https_proxy`/`HTTPS_PROXY` env vars used only for https URLs
  * Added support for using real composer repos as local paths in `create-project` command
  * Added --no-dev to `licenses` command
  * Added support for PHP 7.0 nightly builds
  * Fixed detection of stability when parsing multiple constraints
  * Fixed installs from lock file containing updated composer.json requirement
  * Fixed the autoloader suffix in vendor/autoload.php changing in every build
  * Many minor fixes, documentation additions and UX improvements

### [1.0.0-alpha9] - 2014-12-07

  * Added `remove` command to do the reverse of `require`
  * Added --ignore-platform-reqs to `install`/`update` commands to install even if you are missing a php extension or have an invalid php version
  * Added a warning when abandoned packages are being installed
  * Added auto-selection of the version constraint in the `require` command, which can now be used simply as `composer require foo/bar`
  * Added ability to define custom composer commands using scripts
  * Added `browse` command to open a browser to the given package's repo URL (or homepage with `-H`)
  * Added an `autoload-dev` section to declare dev-only autoload rules + a --no-dev flag to dump-autoload
  * Added an `auth.json` file, with `store-auths` config option
  * Added a `http-basic` config option to store login/pwds to hosts
  * Added failover to source/dist and vice-versa in case a download method fails
  * Added --path (-P) flag to the show command to see the install path of packages
  * Added --update-with-dependencies and --update-no-dev flags to the require command
  * Added `optimize-autoloader` config option to force the `-o` flag from the config
  * Added `clear-cache` command
  * Added a GzipDownloader to download single gzipped files
  * Added `ssh` support in the `github-protocols` config option
  * Added `pre-dependencies-solving` and `post-dependencies-solving` events
  * Added `pre-archive-cmd` and `post-archive-cmd` script events to the `archive` command
  * Added a `no-api` flag to GitHub VCS repos to skip the API but still get zip downloads
  * Added http-basic auth support for private git repos not on github
  * Added support for autoloading `.hh` files when running HHVM
  * Added support for PHP 5.6
  * Added support for OTP auth when retrieving a GitHub API key
  * Fixed isolation of `files` autoloaded scripts to ensure they can not affect anything
  * Improved performance of solving dependencies
  * Improved SVN and Perforce support
  * A boatload of minor fixes, documentation additions and UX improvements

### [1.0.0-alpha8] - 2014-01-06

  * Break: The `install` command now has --dev enabled by default. --no-dev can be used to install without dev requirements
  * Added `composer-plugin` package type to allow extensibility, and deprecated `composer-installer`
  * Added `psr-4` autoloading support and deprecated `target-dir` since it is a better alternative
  * Added --no-plugins flag to replace --no-custom-installers where available
  * Added `global` command to operate Composer in a user-global directory
  * Added `licenses` command to list the license of all your dependencies
  * Added `pre-status-cmd` and `post-status-cmd` script events to the `status` command
  * Added `post-root-package-install` and `post-create-project-cmd` script events to the `create-project` command
  * Added `pre-autoload-dump` script event
  * Added --rollback flag to self-update
  * Added --no-install flag to create-project to skip installing the dependencies
  * Added a `hhvm` platform package to require Facebook's HHVM implementation of PHP
  * Added `github-domains` config option to allow using GitHub Enterprise with Composer's GitHub support
  * Added `prepend-autoloader` config option to allow appending Composer's autoloader instead of the default prepend behavior
  * Added Perforce support to the VCS repository
  * Added a vendor/composer/autoload_files.php file that lists all files being included by the files autoloader
  * Added support for the `no_proxy` env var and other proxy support improvements
  * Added many robustness tweaks to make sure zip downloads work more consistently and corrupted caches are invalidated
  * Added the release date to `composer -V` output
  * Added `autoloader-suffix` config option to allow overriding the randomly generated autoloader class suffix
  * Fixed BitBucket API usage
  * Fixed parsing of inferred stability flags that are more stable than the minimum stability
  * Fixed installation order of plugins/custom installers
  * Fixed tilde and wildcard version constraints to be more intuitive regarding stabilities
  * Fixed handling of target-dir changes when updating packages
  * Improved performance of the class loader
  * Improved memory usage and performance of solving dependencies
  * Tons of minor bug fixes and improvements

### [1.0.0-alpha7] - 2013-05-04

  * Break: For forward compatibility, you should change your deployment scripts to run `composer install --no-dev`. The install command will install dev dependencies by default starting in the next release
  * Break: The `update` command now has --dev enabled by default. --no-dev can be used to update without dev requirements, but it will create an incomplete lock file and is discouraged
  * Break: Removed support for lock files created before 2012-09-15 due to their outdated unusable format
  * Added `prefer-stable` flag to pick stable packages over unstable ones when possible
  * Added `preferred-install` config option to always enable --prefer-source or --prefer-dist
  * Added `diagnose` command to to system/network checks and find common problems
  * Added wildcard support in the update whitelist, e.g. to update all packages of a vendor do `composer update vendor/*`
  * Added `archive` command to archive the current directory or a given package
  * Added `run-script` command to manually trigger scripts
  * Added `proprietary` as valid license identifier for non-free code
  * Added a `php-64bit` platform package that you can require to force a 64bit php
  * Added a `lib-ICU` platform package
  * Added a new official package type `project` for project-bootstrapping packages
  * Added zip/dist local cache to speed up repetitive installations
  * Added `post-autoload-dump` script event
  * Added `Event::getDevMode` to let script handlers know if dev requirements are being installed
  * Added `discard-changes` config option to control the default behavior when updating "dirty" dependencies
  * Added `use-include-path` config option to make the autoloader look for files in the include path too
  * Added `cache-ttl`, `cache-files-ttl` and `cache-files-maxsize` config option
  * Added `cache-dir`, `cache-files-dir`, `cache-repo-dir` and `cache-vcs-dir` config option
  * Added support for using http(s) authentication to non-github repos
  * Added support for using multiple autoloaders at once (e.g. PHPUnit + application both using Composer autoloader)
  * Added support for .inc files for classmap autoloading (legacy support, do not do this on new projects!)
  * Added support for version constraints in show command, e.g. `composer show monolog/monolog 1.4.*`
  * Added support for svn repositories containing packages in a deeper path (see package-path option)
  * Added an `artifact` repository to scan a directory containing zipped packages
  * Added --no-dev flag to `install` and `update` commands
  * Added --stability (-s) flag to create-project to lower the required stability
  * Added --no-progress to `install` and `update` to hide the progress indicators
  * Added --available (-a) flag to the `show` command to display only available packages
  * Added --name-only (-N) flag to the `show` command to show only package names (one per line, no formatting)
  * Added --optimize-autoloader (-o) flag to optimize the autoloader from the `install` and `update` commands
  * Added -vv and -vvv flags to get more verbose output, can be useful to debug some issues
  * Added COMPOSER_NO_INTERACTION env var to do the equivalent of --no-interaction (should be set on build boxes, CI, PaaS)
  * Added PHP 5.2 compatibility to the autoloader configuration files so they can be used to configure another autoloader
  * Fixed handling of platform requirements of the root package when installing from lock
  * Fixed handling of require-dev dependencies
  * Fixed handling of unstable packages that should be downgraded to stable packages when updating to new version constraints
  * Fixed parsing of the `~` operator combined with unstable versions
  * Fixed the `require` command corrupting the json if the new requirement was invalid
  * Fixed support of aliases used together with `<version>#<reference>` constraints
  * Improved output of dependency solver problems by grouping versions of a package together
  * Improved performance of classmap generation
  * Improved mercurial support in various places
  * Improved lock file format to minimize unnecessary diffs
  * Improved the `config` command to support all options
  * Improved the coverage of the `validate` command
  * Tons of minor bug fixes and improvements

### [1.0.0-alpha6] - 2012-10-23

  * Schema: Added ability to pass additional options to repositories (i.e. ssh keys/client certificates to secure private repos)
  * Schema: Added a new `~` operator that should be preferred over `>=`, see http://getcomposer.org/doc/01-basic-usage.md#package-versions
  * Schema: Version constraints `<x.y` are assumed to be `<x.y-dev` unless specified as `<x.y-stable` to reduce confusion
  * Added `config` command to edit/list config values, including --global switch for system config
  * Added OAuth token support for the GitHub API
  * Added ability to specify CLI commands as scripts in addition to PHP callbacks
  * Added --prefer-dist flag to force installs of dev packages from zip archives instead of clones
  * Added --working-dir (-d) flag to change the working directory
  * Added --profile flag to all commands to display execution time and memory usage
  * Added `github-protocols` config key to define the order of preferred protocols for github.com clones
  * Added ability to interactively reset changes to vendor dirs while updating
  * Added support for hg bookmarks in the hg driver
  * Added support for svn repositories not following the standard trunk/branch/tags scheme
  * Fixed git clones of dev versions so that you end up on a branch and not in detached HEAD
  * Fixed "Package not installed" issues with --dev installs
  * Fixed the lock file format to be a snapshot of all the package info at the time of update
  * Fixed order of autoload requires to follow package dependencies
  * Fixed rename() failures with "Access denied" on windows
  * Improved memory usage to be more reasonable and not grow with the repository size
  * Improved performance and memory usage of installs from composer.lock
  * Improved performance of a few essential code paths
  * Many bug small fixes and docs improvements

### [1.0.0-alpha5] - 2012-08-18

  * Added `dump-autoload` command to only regenerate the autoloader
  * Added --optimize to `dump-autoload` to generate a more performant classmap-based autoloader for production
  * Added `status` command to show if any source-installed dependency has local changes, use --verbose to see changed files
  * Added --verbose flag to `install` and `update` that shows the new commits when updating source-installed dependencies
  * Added --no-update flag to `require` to only modify the composer.json file but skip the update
  * Added --no-custom-installers and --no-scripts to `install`, `update` and `create-project` to prevent all automatic code execution
  * Added support for installing archives that contain only a single file
  * Fixed APC related issues in the autoload script on high load websites
  * Fixed installation of branches containing capital letters
  * Fixed installation of custom dev versions/branches
  * Improved the coverage of the `validate` command
  * Improved PEAR scripts/binaries support
  * Improved and fixed the output of various commands
  * Improved error reporting on network failures and some other edge cases
  * Various minor bug fixes and docs improvements

### [1.0.0-alpha4] - 2012-07-04

  * Break: The default `minimum-stability` is now `stable`, [read more](https://groups.google.com/d/topic/composer-dev/_g3ASeIFlrc/discussion)
  * Break: Custom installers now receive the IO instance and a Composer instance in their constructor
  * Schema: Added references for dev versions, requiring `dev-master#abcdef` for example will force the abcdef commit
  * Schema: Added `support` key with some more metadata (email, issues, forum, wiki, irc, source)
  * Schema: Added `!=` operator for version constraints in `require`/`require-dev`
  * Added a recommendation for package names to be `lower-cased/with-dashes`, it will be enforced for new packages on Pacakgist
  * Added `require` command to add a package to your requirements and install it
  * Added a whitelist to `update`. Calling `composer update foo/bar foo/baz` allows you to update only those packages
  * Added support for overriding repositories in the system config (define repositories in ~/.composer/config.json)
  * Added `lib-*` packages to the platform repository, e.g. `lib-pcre` contains the pcre version
  * Added caching of GitHub metadata (faster startup time with custom GitHub VCS repos)
  * Added caching of SVN metadata (faster startup time with custom SVN VCS repos)
  * Added support for file:// URLs to GitDriver
  * Added --self flag to the `show` command to display the infos of the root package
  * Added --dev flag to `create-project` command
  * Added --no-scripts to `install` and `update` commands to avoid triggering the scripts
  * Added `COMPOSER_ROOT_VERSION` env var to specify the version of the root package (fixes some edge cases)
  * Added support for multiple custom installers in one package
  * Added files autoloading method which requires files on every request, e.g. to load functional code
  * Added automatic recovery for lock files that contain references to rewritten (force pushed) commits
  * Improved PEAR repositories support and package.xml extraction
  * Improved and fixed the output of various commands
  * Fixed the order of installation of requirements (they are always installed before the packages requiring them)
  * Cleaned up / refactored the dependency solver code as well as the output for unsolvable requirements
  * Various bug fixes and docs improvements

### [1.0.0-alpha3] - 2012-05-13

  * Schema: Added `require-dev` for development-time requirements (tests, etc), install with --dev
  * Schema: Added author.role to list the author's role in the project
  * Schema: Added `minimum-stability` + `@<stability>` flags in require for restricting packages to a certain stability
  * Schema: Removed `recommend`
  * Schema: `suggest` is now informational and can use any description for a package, not only a constraint
  * Break: vendor/.composer/autoload.php has been moved to vendor/autoload.php, other files are now in vendor/composer/
  * Added caching of repository metadata (faster startup times & failover if packagist is down)
  * Added removal of packages that are not needed anymore
  * Added include_path support for legacy projects that are full of require_once statements
  * Added installation notifications API to allow better statistics on Composer repositories
  * Added support for proxies that require authentication
  * Added support for private github repositories over https
  * Added autoloading support for root packages that use target-dir
  * Added awareness of the root package presence and support for it's provide/replace/conflict keys
  * Added IOInterface::isDecorated to test for colored output support
  * Added validation of licenses based on the [SPDX registry](https://spdx.org/licenses/)
  * Improved repository protocol to have large cacheable parts
  * Fixed various bugs relating to package aliasing, proxy configuration, binaries
  * Various bug fixes and docs improvements

### [1.0.0-alpha2] - 2012-04-03

  * Added `create-project` command to install a project from scratch with composer
  * Added automated `classmap` autoloading support for non-PSR-0 compliant projects
  * Added human readable error reporting when deps can not be solved
  * Added support for private GitHub and SVN repositories (use --no-interaction for CI)
  * Added "file" downloader type to download plain files
  * Added support for authentication with svn repositories
  * Added autoload support for PEAR repositories
  * Improved clones from GitHub which now automatically select between git/https/http protocols
  * Improved `validate` command to give more feedback
  * Improved the `search` & `show` commands output
  * Removed dependency on filter_var
  * Various robustness & error handling improvements, docs fixes and more bug fixes

### 1.0.0-alpha1 - 2012-03-01

  * Initial release

[1.9.1]: https://github.com/composer/composer/compare/1.9.0...1.9.1
[1.9.0]: https://github.com/composer/composer/compare/1.8.6...1.9.0
[1.8.6]: https://github.com/composer/composer/compare/1.8.5...1.8.6
[1.8.5]: https://github.com/composer/composer/compare/1.8.4...1.8.5
[1.8.4]: https://github.com/composer/composer/compare/1.8.3...1.8.4
[1.8.3]: https://github.com/composer/composer/compare/1.8.2...1.8.3
[1.8.2]: https://github.com/composer/composer/compare/1.8.1...1.8.2
[1.8.1]: https://github.com/composer/composer/compare/1.8.0...1.8.1
[1.8.0]: https://github.com/composer/composer/compare/1.7.3...1.8.0
[1.7.3]: https://github.com/composer/composer/compare/1.7.2...1.7.3
[1.7.2]: https://github.com/composer/composer/compare/1.7.1...1.7.2
[1.7.1]: https://github.com/composer/composer/compare/1.7.0...1.7.1
[1.7.0]: https://github.com/composer/composer/compare/1.7.0-RC...1.7.0
[1.7.0-RC]: https://github.com/composer/composer/compare/1.6.5...1.7.0-RC
[1.6.5]: https://github.com/composer/composer/compare/1.6.4...1.6.5
[1.6.4]: https://github.com/composer/composer/compare/1.6.3...1.6.4
[1.6.3]: https://github.com/composer/composer/compare/1.6.2...1.6.3
[1.6.2]: https://github.com/composer/composer/compare/1.6.1...1.6.2
[1.6.1]: https://github.com/composer/composer/compare/1.6.0...1.6.1
[1.6.0]: https://github.com/composer/composer/compare/1.6.0-RC...1.6.0
[1.6.0-RC]: https://github.com/composer/composer/compare/1.5.6...1.6.0-RC
[1.5.6]: https://github.com/composer/composer/compare/1.5.5...1.5.6
[1.5.5]: https://github.com/composer/composer/compare/1.5.4...1.5.5
[1.5.4]: https://github.com/composer/composer/compare/1.5.3...1.5.4
[1.5.3]: https://github.com/composer/composer/compare/1.5.2...1.5.3
[1.5.2]: https://github.com/composer/composer/compare/1.5.1...1.5.2
[1.5.1]: https://github.com/composer/composer/compare/1.5.0...1.5.1
[1.5.0]: https://github.com/composer/composer/compare/1.4.3...1.5.0
[1.4.3]: https://github.com/composer/composer/compare/1.4.2...1.4.3
[1.4.2]: https://github.com/composer/composer/compare/1.4.1...1.4.2
[1.4.1]: https://github.com/composer/composer/compare/1.4.0...1.4.1
[1.4.0]: https://github.com/composer/composer/compare/1.3.3...1.4.0
[1.3.3]: https://github.com/composer/composer/compare/1.3.2...1.3.3
[1.3.2]: https://github.com/composer/composer/compare/1.3.1...1.3.2
[1.3.1]: https://github.com/composer/composer/compare/1.3.0...1.3.1
[1.3.0]: https://github.com/composer/composer/compare/1.3.0-RC...1.3.0
[1.3.0-RC]: https://github.com/composer/composer/compare/1.2.4...1.3.0-RC
[1.2.4]: https://github.com/composer/composer/compare/1.2.3...1.2.4
[1.2.3]: https://github.com/composer/composer/compare/1.2.2...1.2.3
[1.2.2]: https://github.com/composer/composer/compare/1.2.1...1.2.2
[1.2.1]: https://github.com/composer/composer/compare/1.2.0...1.2.1
[1.2.0]: https://github.com/composer/composer/compare/1.2.0-RC...1.2.0
[1.2.0-RC]: https://github.com/composer/composer/compare/1.1.3...1.2.0-RC
[1.1.3]: https://github.com/composer/composer/compare/1.1.2...1.1.3
[1.1.2]: https://github.com/composer/composer/compare/1.1.1...1.1.2
[1.1.1]: https://github.com/composer/composer/compare/1.1.0...1.1.1
[1.1.0]: https://github.com/composer/composer/compare/1.0.3...1.1.0
[1.1.0-RC]: https://github.com/composer/composer/compare/1.0.3...1.1.0-RC
[1.0.3]: https://github.com/composer/composer/compare/1.0.2...1.0.3
[1.0.2]: https://github.com/composer/composer/compare/1.0.1...1.0.2
[1.0.1]: https://github.com/composer/composer/compare/1.0.0...1.0.1
[1.0.0]: https://github.com/composer/composer/compare/1.0.0-beta2...1.0.0
[1.0.0-beta2]: https://github.com/composer/composer/compare/1.0.0-beta1...1.0.0-beta2
[1.0.0-beta1]: https://github.com/composer/composer/compare/1.0.0-alpha11...1.0.0-beta1
[1.0.0-alpha11]: https://github.com/composer/composer/compare/1.0.0-alpha10...1.0.0-alpha11
[1.0.0-alpha10]: https://github.com/composer/composer/compare/1.0.0-alpha9...1.0.0-alpha10
[1.0.0-alpha9]: https://github.com/composer/composer/compare/1.0.0-alpha8...1.0.0-alpha9
[1.0.0-alpha8]: https://github.com/composer/composer/compare/1.0.0-alpha7...1.0.0-alpha8
[1.0.0-alpha7]: https://github.com/composer/composer/compare/1.0.0-alpha6...1.0.0-alpha7
[1.0.0-alpha6]: https://github.com/composer/composer/compare/1.0.0-alpha5...1.0.0-alpha6
[1.0.0-alpha5]: https://github.com/composer/composer/compare/1.0.0-alpha4...1.0.0-alpha5
[1.0.0-alpha4]: https://github.com/composer/composer/compare/1.0.0-alpha3...1.0.0-alpha4
[1.0.0-alpha3]: https://github.com/composer/composer/compare/1.0.0-alpha2...1.0.0-alpha3
[1.0.0-alpha2]: https://github.com/composer/composer/compare/1.0.0-alpha1...1.0.0-alpha2
