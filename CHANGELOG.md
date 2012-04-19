* 1.0.0-alpha3

  * Schema: Added 'require-dev' for development-time requirements (tests, etc), install with --dev
  * Schema: Removed 'recommend'
  * Schema: 'suggest' is now informational and can use any description for a package, not only a constraint
  * Break: .composer/autoload.php and other files in vendor/.composer have been moved to vendor/
  * Added caching of repository metadata (faster startup times & failover if packagist is down)
  * Added include_path support for legacy projects that are full of require_once statements
  * Added installation notifications API to allow better statistics on Composer repositories
  * Improved repository protocol to have large cacheable parts

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
