* 1.0.0-alpha4 (2012-07-04)

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

* 1.0.0-alpha3 (2012-05-13)

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

* 1.0.0-alpha2 (2012-04-03)

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

* 1.0.0-alpha1 (2012-03-01)

  * Initial release
