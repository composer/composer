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
  * Added validation of licenses based on the [SPDX registry](http://www.spdx.org/licenses/)
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
