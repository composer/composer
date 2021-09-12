# Upgrade guides for Composer 1.x to 2.0

## For composer CLI users

- The new platform-check feature means that Composer checks the runtime PHP version and available extensions to ensure they match the project dependencies. If a mismatch is found, it exits with error details to make sure problems are not overlooked. To avoid issues when deploying to production it is recommended to run `composer check-platform-reqs` with the production PHP process as part of your build or deployment process.
- If a package exists in a higher priority repository, it will now be entirely ignored in lower priority repositories. See [repository priorities](https://getcomposer.org/repoprio) for details.
- Invalid PSR-0 / PSR-4 class configurations will not autoload anymore in optimized-autoloader mode, as per the warnings introduced in 1.10
- On linux systems supporting the XDG Base Directory Specification, Composer will now prefer using XDG_CONFIG_DIR/composer over `~/.composer` if both are available (1.x used `~/.composer` first)
- Package names now must comply to our [naming guidelines](doc/04-schema.md#name) or Composer will abort, as per the warnings introduced in 1.8.1
- Deprecated --no-suggest flag as it is not needed anymore
- PEAR support (repository, downloader, etc.) has been removed
- `update` now lists changes to the lock file first (update step), and then the changes applied when installing the lock file to the vendor dir (install step)
- `HTTPS_PROXY_REQUEST_FULLURI` if not specified will now default to false as this seems to work better in most environments
- `dev-trunk`, `dev-master` and `dev-default` are no longer aliases for each other. The exact branch names are now preserved.

## For integrators and plugin authors

- composer-plugin-api has been bumped to 2.0.0 - you can detect which version of Composer you run via `PluginInterface::PLUGIN_API_VERSION`
- `PluginInterface` added a deactivate (so plugin can stop whatever it is doing) and an uninstall (so the plugin can remove any files it created or do general cleanup) method.
- Plugins implementing `EventSubscriberInterface` will be deregistered from the EventDispatcher automatically when being deactivated, nothing to do there.
- `Pool` objects are now created via the `RepositorySet` class, you should use that in case you were using the `Pool` class directly.
- Custom installers extending from LibraryInstaller should be aware that in Composer 2 it MAY return PromiseInterface instances when calling parent::install/update/uninstall/installCode/removeCode. See [composer/installers](https://github.com/composer/installers/commit/5006d0c28730ade233a8f42ec31ac68fb1c5c9bb) for an example of how to handle this best.
- The `Composer\Installer` class changed quite a bit internally, but the inputs are almost the same:
  - `setAdditionalInstalledRepository` is now `setAdditionalFixedRepository`
  - `setUpdateWhitelist` is now `setUpdateAllowList`
  - `setWhitelistDependencies`, `setWhitelistTransitiveDependencies` and `setWhitelistAllDependencies` are now all rolled into `setUpdateAllowTransitiveDependencies` which takes one of the `Request::UPDATE_*` constants
  - `setSkipSuggest` is gone
- `vendor/composer/installed.json` format changed:
  - packages are now wrapped into a `"packages"` top level key instead of the whole file being the package array
  - packages now contain an `"installed-path"` key which lists where they were installed
  - there is a top level `"dev"` key which stores whether dev requirements were installed or not
- Removed `OperationInterface::getReason` as the data was not accurate. There is no replacement available.
- `PreFileDownloadEvent` now receives an `HttpDownloader` instance instead of `RemoteFilesystem`, and that instance cannot be overridden by listeners anymore, you can however call setProcessedUrl or setCustomCacheKey.
- `VersionSelector::findBestCandidate`'s third argument (phpVersion) was removed in favor of passing in a complete PlatformRepository instance into the constructor
- `InitCommand::determineRequirements`'s fourth argument (phpVersion) should now receive a complete PlatformRepository instance or null if platform requirements are to be ignored
- `IOInterface` now extends PSR-3's `LoggerInterface`, and has new `writeRaw` + `writeErrorRaw` methods
- `RepositoryInterface` changes:
  - A new `loadPackages(array $packageNameMap, array $acceptableStabilities, array $stabilityFlags)` function was added for use during pool building
  - `search` now has a third `$type` argument
  - A new `getRepoName()` function was added to describe the repository
  - A new `getProviders()` function was added to list packages providing a given package's name
- Removed `BaseRepository` abstract class
- `DownloaderInterface` changes:
  - `download` now receives a third `$prevPackage` argument for updates
  - `download` should now only do network operations to prepare the package for installation but not actually install anything
  - `prepare` (do user prompts or any checks which need to happen to make sure that install/update/remove will most likely succeed), `install` (should do the non-network part that `download` used to do) and `cleanup` (cleaning up anything that may be left over) were added as new steps in the package install flow
  - All packages get first downloaded, then all together prepared, then all together installed/updated/uninstalled, then finally cleanup is called for all. Therefore for error recovery it is important to avoid failing during install/update/uninstall as much as possible, and risky things or user prompts should happen in the prepare step rather. In case of failure, cleanup() will be called so that changes can be undone as much as possible.
- If you used `RemoteFilesystem` you probably should use `HttpDownloader` instead now
- `PRE_DEPENDENCIES_SOLVING` and `POST_DEPENDENCIES_SOLVING` events have been removed, use the new `PRE_OPERATIONS_EXEC`, `PRE_POOL_CREATE` or other existing events instead or talk to us if you think you really need this. See below for more details.
- The bundled composer/semver is now the 3.x range, see release notes for [2.0](https://github.com/composer/semver/releases/tag/2.0.0) and [3.0](https://github.com/composer/semver/releases/tag/3.0.0) for the minor breaking changes there
- Run Composer with COMPOSER_DEBUG_EVENTS=1 set in the environment to show which events happen which might help you.

### Detailed differences in event flow during dependency resolution, composer updates and installs

#### Composer v1

- Composer resolves dependencies (dispatching PRE/POST_DEPENDENCIES_SOLVING)
- It then iterates over all packages one by one (dispatching PRE_PACKAGE_INSTALL/UPDATE/UNINSTALL, then PRE_FILE_DOWNLOAD if needed, then POST_PACKAGE_\*)
- And finally writes the lock file at the end

#### Composer v2

The update and install process have been split up.

Update does:

- Composer resolves dependencies (dispatching PRE_POOL_CREATE)
- It then writes the lock file and that's the end of the update

Install then does:

- Dispatches PRE_OPERATIONS_EXEC with the full list of operations to be executed
- Downloads all the packages not in cache yet in parallel (dispatching PRE_FILE_DOWNLOAD for those not in cache yet)
- It then iterates over all packages and executes updates/installs/uninstalls in parallel (dispatching PRE_PACKAGE_INSTALL/UPDATE/UNINSTALL then POST_PACKAGE_\* but one package started last may finish installing before another is done for example).

## For Composer repository implementors

Composer 2.0 adds support for a new Composer repository format.

It is possible to build a repository which is compatible with both Composer v1 and v2, you keep everything you had and simply add the new fields in `packages.json`.

Here are examples of the new values from packagist.org:

### metadata-url

`"metadata-url": "/p2/%package%.json",`

This new metadata-url should serve all packages which are in the repository.

- Whenever Composer looks for a package, it will replace `%package%` by the package name, and fetch that URL.
- If dev stability is allowed for the package, it will also load the URL again with `$packageName~dev` (e.g. `/p2/foo/bar~dev.json` to look for `foo/bar`'s dev versions).
- Caching is done via the use of If-Modified-Since header, so make sure you return Last-Modified headers and that they are accurate.
- Any requested package which does not exist MUST return a 404 status code, which will indicate to Composer that this package does not exist in your repository. Make sure the 404 response is fast to avoid blocking Composer. Avoid redirects to alternative 404 pages.
- The `foo/bar.json` and `foo/bar~dev.json` files containing package versions MUST contain only versions for the foo/bar package, as `{"packages":{"foo/bar":[ ... versions here ... ]}}`.
- The array of versions can also optionally be minified using `Composer\Util\MetadataMinifier::minify()`. If you do that, you should add a `"minified": "composer/2.0"` key at the top level to indicate to Composer it must expand the version list back into the original data. See https://repo.packagist.org/p2/monolog/monolog.json for an example.

If your repository only has a small number of packages, and you want to avoid the 404-requests, you can also specify an `"available-packages"` key in `packages.json` which should be an array with all the package names that your repository contain. Alternatively you can specify an `"available-package-patterns"` key which is an array of package name patterns (with `*` matching any string, e.g. `vendor/*` would make composer look up every matching package name in this repository).

### providers-api

`"providers-api": "https://packagist.org/providers/%package%.json",`

The providers-api is optional, but if you implement it it should return packages which provide a given package name, but not the package which has that name. For example https://packagist.org/providers/monolog/monolog.json lists some package which have a "provide" rule for monolog/monolog, but it does not list monolog/monolog itself.

### list

This is also optional, it should accept an optional `?filter=xx` query param, which can contain `*` as wildcards matching any substring.

It must return an array of package names as `{"packageNames": ["a/b", "c/d"]}`. See <https://packagist.org/packages/list.json?filter=composer/*> for example.

It should return the names of package which names match the filter (or all names if no filter is present). Replace/provide rules should not be considered here.
