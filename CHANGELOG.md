* 1.0.0-alpha2

  * Added `create-project` command to install a project from scratch with composer
  * Added automated `classmap` autoloading support for non-PSR-0 compliant projects
  * Improved clones from GitHub which now automatically select between git/https/http protocols
  * Added support for private GitHub repositories (use --no-interaction for CI)
  * Improved `validate` command to give more feedback
  * Added "file" downloader type to download plain files
  * Added support for authentication with svn repositories
  * Removed dependency on filter_var
  * Improved the `search` & `show` commands output
  * Various robustness & error handling improvements, docs fixes and more

* 1.0.0-alpha1 (2012-03-01)

  * Initial release
