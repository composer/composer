### [2.2.12] 2022-04-13

  * Security: Fixed command injection vulnerability in HgDriver/GitDriver (GHSA-x7cr-6qr6-2hh6 / CVE-2022-24828)
  * Fixed curl downloader not retrying when a DNS resolution failure occurs (#10716)
  * Fixed composer.lock file still being used/read when the `lock` config option is disabled (#10726)
  * Fixed `validate` command checking the lock file even if the `lock` option is disabled (#10723)

### [2.2.11] 2022-04-01

  * Added missing config.bitbucket-oauth in composer-schema.json
  * Added --2.2 flag to `self-update` to pin the Composer version to the 2.2 LTS range (#10682)
  * Updated semver, jsonlint deps for minor fixes
  * Fixed generation of autoload crashing if a package has a broken path (#10688)
  * Removed dev-master=>dev-main alias from #10372 as it does not work when reloading from lock file and extracting dev deps (#10651)

### [2.2.10] 2022-03-29

  * Fixed Bitbucket authorization detection due to API changes (#10657)
  * Fixed validate command warning about dist/source keys if defined (#10655)
  * Fixed deletion/handling of corrupted 0-bytes zip archives (#10666)

### [2.2.9] 2022-03-15

  * Fixed regression with plugins that modify install path of packages, [see docs](https://getcomposer.org/doc/articles/plugins.md#plugin-modifies-install-path) if you are authoring such a plugin (#10621)

### [2.2.8] 2022-03-15

  * Fixed `files` autoloading sort order to be fully deterministic (#10617)
  * Fixed pool optimization pass edge cases (#10579)
  * Fixed `require` command failing when `self.version` is used as constraint (#10593)
  * Fixed --no-ansi / undecorated output still showing color in repo warnings (#10601)
  * Performance improvement in pool optimization step (composer/semver#131)

### [2.2.7] 2022-02-25

  * Allow installation together with composer/xdebug-handler ^3 (#10528)
  * Fixed support for packages with no licenses in `licenses` command output (#10537)
  * Fixed handling of `allow-plugins: false` which kept warning (#10530)
  * Fixed enum parsing in classmap generation when the enum keyword is not lowercased (#10521)
  * Fixed author parsing in `init` command requiring an email whereas the schema allows a name only (#10538)
  * Fixed issues in `require` command when requiring packages which do not exist (but are provided by something else you require) (#10541)
  * Performance improvement in pool optimization step (#10546)

### [2.2.6] 2022-02-04

  * BC Break: due to an oversight, the `COMPOSER_BIN_DIR` env var for binaries added in Composer 2.2.2 had to be renamed to `COMPOSER_RUNTIME_BIN_DIR` (#10512)
  * Fixed enum parsing in classmap generation with syntax like `enum foo:string` without space after `:` (#10498)
  * Fixed package search not urlencoding the input (#10500)
  * Fixed `reinstall` command not firing `pre-install-cmd`/`post-install-cmd` events (#10514)
  * Fixed edge case in path repositories where a symlink: true option would be ignored on old Windows and old PHP combos (#10482)
  * Fixed test suite compatibility with latest symfony/console releases (#10499)
  * Fixed some error reporting edge cases (#10484, #10451, #10493)

### [2.2.5] 2022-01-21

  * Disabled `composer/package-versions-deprecated` by default as it can function using `Composer\InstalledVersions` at runtime (#10458)
  * Fixed artifact repositories crashing if a phar file was present in the directory (#10406)
  * Fixed binary proxy issue on PHP <8 when fseek is used on the proxied binary path (#10468)
  * Fixed handling of non-string versions in package repositories metadata (#10470)

### [2.2.4] 2022-01-08

  * Fixed handling of process timeout when running async processes during installation
  * Fixed GitLab API handling when projects have a repository disabled (#10440)
  * Fixed reading of environment variables (e.g. APPDATA) containing unicode characters to workaround a PHP bug on Windows (#10434)
  * Fixed partial update issues with path repos missing if a path repo is required by a path repo (#10431)
  * Fixed support for sourcing binaries via the new bin proxies ([#10389](https://github.com/composer/composer/issues/10389#issuecomment-1007372740))
  * Fixed messaging when GitHub tokens need SSO authorization (#10432)

### [2.2.3] 2021-12-31

  * Fixed issue with PHPUnit and process isolation now including PHPUnit <6.5 (#10387)
  * Fixed interoperability issue with laminas/laminas-zendframework-bridge and Composer 2.2 (#10401)
  * Fixed binary proxies for shell scripts to work correctly when they are symlinked (jakzal/phpqa#336)
  * Fixed overly greedy pool optimization in cases where a locked package is not required by anything anymore in a partial update (#10405)

### [2.2.2] 2021-12-29

  * Added [`COMPOSER_BIN_DIR` env var and `_composer_bin_dir` global](https://getcomposer.org/doc/articles/vendor-binaries.md#finding-the-composer-bin-dir-from-a-binary) containing the path to the bin-dir for binaries. Packages relying on finding the bin dir with `$BASH_SOURCES[0]` will need to update their binaries (#10402)
  * Fixed issue when new binary proxies are combined with PHPUnit and process isolation (#10387)
  * Fixed deprecation warnings when using Symfony 5.4+ and requiring composer/composer itself (#10404)
  * Fixed UX of plugin warnings (#10381)

### [2.2.1] 2021-12-22

  * Fixed plugin autoloading including files autoload rules from the root package (#10382)
  * Fixed issue parsing php files with unterminated comments found inside backticks (#10385)

### [2.2.0] 2021-12-22

  * Added support for using `dev-main` as the default path repo package version if no VCS info is available (#10372)
  * Added --no-scripts as a globally supported flag to all Composer commands to disable scripts execution (#10371)
  * Fixed self-update failing in some edge cases due to loading plugins (#10371)
  * Fixed display of conflicts showing the wrong package name in some conditions (#10355)

### [2.2.0-RC1] 2021-12-08

  * Bumped `composer-runtime-api` and `composer-plugin-api` to `2.2.0`
  * UX Change: Added [`allow-plugins`](https://getcomposer.org/doc/06-config.md#allow-plugins) config value to enhance security against runtime execution, this will prompt you the first time you use a plugin and may hang pipelines if they aren't using --no-interaction (-n) as they should (#10314)
  * Added an optimization pass to reduce the amount of redundant inspected during resolution, drastically improving memory and CPU usage (#9261, #9620)
  * Added a [global $_composer_autoload_path variable](https://getcomposer.org/doc/articles/vendor-binaries.md#finding-the-composer-autoloader-from-a-binary) containing the path to autoload.php for binaries (#10137)
  * Added wildcard support to --ignore-platform-req (e.g. `ext-*`) (#10083)
  * Added support for ignoring the upper bound of platform requirements using "name+" notation e.g. using `--ignore-platform-req=php+` would allow installing a package requiring `php: 8.0.*` on PHP 8.1, but not on PHP 7.4. Useful for CI builds of upcoming PHP versions (#10318)
  * Added support for setting platform packages to false in config.platform to disable/hide them (#10308)
  * Added [`use-parent-dir`](https://getcomposer.org/doc/06-config.md#use-parent-dir) option to configure the prompt for using composer.json in upper directory when none is present in current dir (#10307)
  * Added [`composer` platform package](https://getcomposer.org/doc/articles/composer-platform-dependencies.md) which is always the exact version of Composer running unlike `composer-*-api` packages (#10313)
  * Added a --source flag to `config` command to show where config values are loaded from (#10129)
  * Added support for `files` autoloaders in the runtime scripts/plugins contexts (#10065)
  * Added retry behavior on certain http status and curl error codes (#10162)
  * Added abandoned flag display in search command output
  * Added support for --ignore-platform-reqs in `outdated` command (#10293)
  * Added --only-vendor (-O) flag to `search` command to search (and return) vendor names (#10336)
  * Added COMPOSER_NO_DEV environment variable to set the --no-dev flag (#10262)
  * Fixed `archive` command to behave more like git archive, gitignore/hgignore are not taken into account anymore, and gitattributes support was improved (#10309)
  * Fixed unlocking of replacers when a replaced package is unlocked (#10280)
  * Fixed auto-unlocked path repo packages also unlocking their transitive deps when -w/-W is used (#10157)
  * Fixed handling of recursive package links (e.g. requiring or replacing oneself)
  * Fixed env var reads to check $_SERVER and $_ENV before getenv for broader ecosystem compatibility (#10218)
  * Fixed `archive` command to produce archives with files sorted by name (#10274)
  * Fixed VcsRepository issues where server failure could cause missing tags/branches (#10319)
  * Fixed some error reporting issues (#10283, #10339)

### [2.1.14] 2021-11-30

  * Fixed invalid release build

### [2.1.13] 2021-11-30

  * Removed `symfony/console ^6` support as we cannot be compatible until Composer 2.3.0 is released. If you have issues with Composer required as a dependency + Symfony make sure you stay on Symfony 5.4 for now. (#10321)

### [2.1.12] 2021-11-09

  * Fixed issues in proxied binary files relying on __FILE__ / __DIR__ on php <8 (#10261)
  * Fixed 9999999-dev being shown in some cases by the `show` command (#10260)
  * Fixed GitHub Actions output escaping regression on PHP 8.1 (#10250)

### [2.1.11] 2021-11-02

  * Fixed issues in proxied binary files when using declare() on php <8 (#10249)
  * Fixed GitHub Actions output escaping issues (#10243)

### [2.1.10] 2021-10-29

  * Added type annotations to all classes, which may have an effect on CI/static analysis for people using Composer as a dependency (#10159)
  * Fixed CurlDownloader requesting gzip encoding even when no gzip support is present (#10153)
  * Fixed regression in 2.1.6 where the help command was not working for plugin commands (#10147)
  * Fixed warning showing when an invalid cache dir is configured but unused (#10125)
  * Fixed `require` command reverting changes even though dependency resolution succeeded when something fails in scripts for example (#10118)
  * Fixed `require` not finding the right package version when some newly required extension is missing from the system (#10167)
  * Fixed proxied binary file issues, now using output buffering (e1dbd65aff)
  * Fixed and improved error reporting in several edge cases (#9804, #10136, #10163, #10224, #10209)
  * Fixed some more Windows CLI parameter escaping edge cases

### [2.1.9] 2021-10-05

  * Security: Fixed command injection vulnerability on Windows (GHSA-frqg-7g38-6gcf / CVE-2021-41116)
  * Fixed classmap parsing with a new class parser which does not rely on regexes anymore (#10107)
  * Fixed inline git credentials showing up in output in some conditions (#10115)
  * Fixed support for running updates while offline as long as the cache contains enough information (#10116)
  * Fixed `show --all foo/bar` which as of 2.0.0 was not showing all versions anymore but only the installed one (#10095)
  * Fixed VCS repos ignoring some versions silently when the API rate limit is reached (#10132)
  * Fixed CA bundle to remove the expired Let's Encrypt root CA

### [2.1.8] 2021-09-15

  * Fixed regression in 2.1.7 when parsing classmaps in files containing invalid Unicode (#10102)

### [2.1.7] 2021-09-14

  * Added many type annotations internally, which may have an effect on CI/static analysis for people using Composer as a dependency. This work will continue in following releases
  * Fixed regression in 2.1.6 when parsing classmaps with empty heredocs (#10067)
  * Fixed regression in 2.1.6 where list command was not showing plugin commands (#10075)
  * Fixed issue handling package updates where the package type changed (#10076)
  * Fixed docker being detected as WSL when run inside WSL (#10094)

### [2.1.6] 2021-08-19

  * Updated internal PHAR signatures to be SHA512 instead of SHA1
  * Fixed uncaught exception handler regression (#10022)
  * Fixed more PHP 8.1 deprecation warnings (#10036, #10038, #10061)
  * Fixed corrupted zips in the cache from blocking installs until a cache clear, the bad archives are now deleted automatically on first failure (#10028)
  * Fixed URL sanitizer handling of new github tokens (#10048)
  * Fixed issue finding classes with very long heredocs in classmap autoload (#10050)
  * Fixed proc_open being required for simple installs from zip, as well as diagnose (#9253)
  * Fixed path repository bug causing symlinks to be left behind after a package is uninstalled (#10023)
  * Fixed issue in 7-zip support on windows with certain archives (#10058)
  * Fixed bootstrapping process to avoid loading the composer.json and plugins until necessary, speeding things up slightly (#10064)
  * Fixed lib-openssl detection on FreeBSD (#10046)
  * Fixed support for `ircs://` protocol for support.irc composer.json entries

### [2.1.5] 2021-07-23

  * Fixed `create-project` creating a `php:` directory in the directory it was executed in (#10020, #10021)
  * Fixed curl downloader to respect default_socket_timeout if it is bigger than our default 300s (#10018)

### [2.1.4] 2021-07-22

  * Fixed PHP 8.1 deprecation warnings (#10008)
  * Fixed support for working within UNC/WSL paths on Windows (#9993)
  * Fixed 7-zip support to also be looked up on Linux/macOS as 7z or 7zz (#9951)
  * Fixed repositories' `only`/`exclude` properties to avoid matching names as sub-strings of full package names (#10001)
  * Fixed open_basedir regression from #9855
  * Fixed schema errors being reported incorrectly in some conditions (#9986)
  * Fixed `archive` command not working with async archive extraction
  * Fixed `init` command being able to generate an invalid composer.json (#9986)

### [2.1.3] 2021-06-09

  * Add "symlink" option for "bin-compat" config to force symlinking even on WSL/Windows (#9959)
  * Fixed source binaries not being made executable when symlinks cannot be used (#9961)
  * Fixed more deletion edge cases (#9955, #9956)
  * Fixed `dump-autoload` command not dispatching scripts anymore, regressed in 2.1.2 (#9954)

### [2.1.2] 2021-06-07

  * Added `--dev` to `dump-autoload` command to allow force-dumping dev autoload rules even if dev requirements are not present (#9946)
  * Fixed `--no-scripts` disabling events for plugins too instead of only disabling script handlers, using `--no-plugins` is the way to disable plugins (#9942)
  * Fixed handling of deletions during package installs on some filesystems (#9945, #9947)
  * Fixed undefined array access when using "@php <absolute path>" in a script handler (#9943)
  * Fixed usage of InstalledVersions when loaded from composer/composer installed as a dependency and runtime Composer is v1 (#9937)

### [2.1.1] 2021-06-04

  * Fixed regression in autoload generation when --no-scripts is used (#9935)
  * Fixed `outdated` color legend to have the right color in the right place (#9939)
  * Fixed PCRE bug causing a previously valid pattern to fail to match (#9941)
  * Fixed JsonFile::validateSchema regression when used as a library to validate custom schema files (#9938)

### [2.1.0] 2021-06-03

  * Fixed PHP 8.1 deprecation warning (#9932)
  * Fixed env var handling when variables_order includes E and symfony/console 3.3.15+ is in use (#9930)

### [2.1.0-RC1] 2021-06-02

  * Bumped `composer-runtime-api` and `composer-plugin-api` to `2.1.0`
  * UX Change: The default install method for packages is now always dist/zip, even for dev packages, added `--prefer-install=auto` if you want the old behavior (#9603)
  * UX Change: Packages from `path` repositories which are symlinked in the vendor dir will always be updated in partial updates to avoid mistakes when the original composer.json changes but the symlinked package is not explicitly updated (#9765)
  * Added `reinstall` command that takes one or more package names, including wildcard (`*`) support, and removes then reinstalls them in the exact same version they had (#9915)
  * Added support for parallel package installs on Windows via [7-Zip](https://www.7-zip.org/) if it is installed (#9875)
  * Added detection of invalid composer.lock files that do not fullfil the composer.json requirements to `validate` command (#9899)
  * Added `InstalledVersions::getInstalledPackagesByType(string $type)` to retrieve installed plugins for example, [read more](https://getcomposer.org/doc/07-runtime.md#knowing-which-packages-of-a-given-type-are-installed) (#9699)
  * Added `InstalledVersions::getInstalledPath(string $packageName)` to retrieve the install path of a given package, [read more](https://getcomposer.org/doc/07-runtime.md#knowing-the-path-in-which-a-package-is-installed) (#9699)
  * Added flag to `InstalledVersions::isInstalled()` to allow excluding dev requirements from that check (#9682)
  * Added support for PHP 8.1 enums in autoloader / classmap generation (#9670)
  * Added support for using `@php binary-name foo` in scripts to refer to a binary without using its full path, but forcing to use the same PHP version as Composer used (#9726)
  * Added `--format=json` support to the `fund` command (#9678)
  * Added `--format=json` support to the `search` command (#9747)
  * Added `COMPOSER_DEV_MODE` env var definition within the run-script command for compatibility (#9793)
  * Added async uninstall of packages (#9618)
  * Added color legend to `outdated` and `show --latest` commands (#9716)
  * Added `secure-svn-domains` config option to mark secure svn:// hostnames and suppress warnings without disabling secure-http (#9872)
  * Added `gitlab-protocol` config option to allow forcing `git` or `http` URLs for all gitlab repos loaded inline, instead of the default of git for private and http for public (#9401)
  * Added generation of autoload rules in `init` command (#9829)
  * Added source/dist validation in `validate` command
  * Added automatic detection of WSL when generating binaries and use `bin-compat:full` implicitly (#9855)
  * Added automatic detection of the --no-dev state for `dump-autoload` based on the last install run (#9714)
  * Added warning/prompt to `require` command if requiring a package that already exists in require-dev or vice versa (#9542)
  * Added information about package conflicts in the `why`/`why-not` commands (#9693)
  * Removed version argument from `why` command as it was not needed (#9729)
  * Fixed `why-not` command to always require a specific version as it is useless without (#9729)
  * Fixed cache dir on macOS to follow OS guidelines, it is now in ~/Library/Caches/composer (#9898)
  * Fixed composer.json JSON schema to avoid having name/description required by default (#9912)
  * Fixed support for running inside WSL paths from a Windows PHP/Composer (#9861)
  * Fixed InstalledVersions to include the original doc blocks when installed from a Composer phar file
  * Fixed `require` command to use `*` as constraint for extensions bundled with PHP instead of duplicating the PHP constraint (#9483)
  * Fixed `search` output to be aligned and avoid wrapped long lines to be more readable (#9455)
  * Error output improvements for many cases (#9876, #9837, #9928, and some smaller improvements)

### [2.0.14] 2021-05-21

  * Updated composer/xdebug-handler to 2.0 which adds supports for Xdebug 3
  * Fixed handling of inline-update-constraints with references or stability flags (#9847)
  * Fixed async processes erroring in an unclear way when they failed to start (#9808)
  * Fixed support for the upcoming Symfony 6.0 release when Composer is installed as a library (#9896)
  * Fixed progress output missing newlines on PowerShell, and disable progress output by default when CI env var is present (#9621)
  * Fixed support for Vagrant/VirtualBox filesystem slowness when installing binaries from packages (#9627)
  * Fixed type annotations for the InstalledVersions class
  * Deprecated InstalledVersions::getRawData in favor of InstalledVersions::getAllRawData (#9816)

### [2.0.13] 2021-04-27

  * Security: Fixed command injection vulnerability in HgDriver/HgDownloader and hardened other VCS drivers and downloaders (GHSA-h5h8-pc6h-jvvx / CVE-2021-29472)
  * Fixed install step at the end of the init command to take new dependencies into account correctly
  * Fixed `update --lock` listing updates which were not really happening (#9812)
  * Fixed support for --no-dev combined with --locked in outdated and show commands (#9788)

### [2.0.12] 2021-04-01

  * Fixed support for new GitHub OAuth token format (#9757)
  * Fixed support for Vagrant/VirtualBox filesystem slowness by adding short sleeps in some places (#9627)
  * Fixed unclear error reporting when a package is in the lock file but not in the remote repositories (#9750)
  * Fixed processes silently ignoring the CWD when it does not exist
  * Fixed new Windows bin handling to avoid proxying phar files (#9742)
  * Fixed issue extracting archives into paths that already exist, fixing problems with some custom installers (composer/installers#479)
  * Fixed support for branch names starting with master/trunk/default (#9739)
  * Fixed self-update to preserve phar file permissions on Windows (#9733)
  * Fixed detection of hg version when localized (#9753)
  * Fixed git execution failures to also include the stdout output (#9720)

### [2.0.11] 2021-02-24

  * Reverted "Fixed runtime autoloader registration (for plugins and script handlers) to prefer the project dependencies over the bundled Composer ones" as it caused more problems than expected

### [2.0.10] 2021-02-23

  * Added COMPOSER_MAX_PARALLEL_HTTP to let people set a lower amount of parallel requests if needed
  * Fixed autoloader registration when plugins are loaded, which may impact plugins relying on this bug (if you use `symfony/flex` make sure you upgrade it to 1.12.2+ to fix `dump-env` issues)
  * Fixed `exec` command suppressing output in some circumstances
  * Fixed Windows/cmd.exe support for script handlers defined as `path/to/foo`, which are now rewritten internally to `path\to\foo` when needed
  * Fixed bin handling on Windows for PHP scripts, to more closely match symlinks and allow `@php vendor/bin/foo` to work cross-platform
  * Fixed Git for Windows/Git Bash not being detected correctly as an interactive shell (regression since 2.0.7)
  * Fixed regression handling some private Bitbucket repository clones
  * Fixed Ctrl-C/SIGINT handling during downloads to correctly abort as soon as possible
  * Fixed runtime autoloader registration (for plugins and script handlers) to prefer the project dependencies over the bundled Composer ones
  * Fixed numeric default branches being aliased as 9999999-dev internally. This alias now only applies to default branches being non-numeric (e.g. `dev-main`)
  * Fixed support for older lib-sodium versions
  * Fixed various minor issues

### [2.0.9] 2021-01-27

  * Added warning if the curl extension is not enabled as it significantly degrades performance
  * Fixed InstalledVersions to report all packages when several vendor dirs are present in the same runtime
  * Fixed download speed when downloading large files
  * Fixed `archive` and path repo copies mishandling some .gitignore paths
  * Fixed root package classes not being available to the plugins/scripts during the initial install
  * Fixed cache writes to be atomic and better support multiple Composer processes running in parallel
  * Fixed preg jit issues when `config` or `require` modifies large composer.json files
  * Fixed compatibility with envs having open_basedir restrictions
  * Fixed exclude-from-classmap causing regex issues when having too many paths
  * Fixed compatibility issue with Symfony 4/5
  * Several small performance and debug output improvements

### [2.0.8] 2020-12-03

  * Fixed packages with aliases not matching conflicts which match the alias
  * Fixed invalid reports of uncommitted changes when using non-default remotes in vendor dir
  * Fixed curl error handling edge cases
  * Fixed cached git repositories becoming stale by having a `git gc` applied to them periodically
  * Fixed issue initializing plugins when using dev packages
  * Fixed update --lock / mirrors failing to update in some edge cases
  * Fixed partial update with --with-dependencies failing in some edge cases with some nonsensical error

### [2.0.7] 2020-11-13

  * Fixed detection of TTY mode, made input non-interactive automatically if STDIN is not a TTY
  * Fixed root aliases not being present in lock file if not required by anything else
  * Fixed `remove` command requiring a lock file to be present
  * Fixed `Composer\InstalledVersions` to always contain up to date data during installation
  * Fixed `status` command breaking on slow networks
  * Fixed order of POST_PACKAGE_* events to occur together once all installations of a package batch are done

### [2.0.6] 2020-11-07

  * Fixed regression in 2.0.5 dealing with custom installers which do not pass absolute paths

### [2.0.5] 2020-11-06

  * Disabled platform-check verification of extensions by default (now defaulting `php-only`), set platform-check to `true` if you want a complete check
  * Improved platform-check handling of issue reporting
  * Fixed platform-check to only check non-dev requires even if require-dev dependencies are installed
  * Fixed issues dealing with custom installers which return trailing slashes in getInstallPath (ideally avoid doing this as there might be other issues left)
  * Fixed issues when curl functions are disabled
  * Fixed gitlab-domains/github-domains to make sure if they are overridden the default value remains present
  * Fixed issues removing/upgrading packages from path repositories on Windows
  * Fixed regression in 2.0.4 when handling of git@bitbucket.org URLs in vcs repositories
  * Fixed issue running create-project in current directory on Windows

### [2.0.4] 2020-10-30

  * Fixed `check-platform-req` command not being clear on what packages are checked, and added a --lock flag to explicitly check the locked packages
  * Fixed `config` & `create-project` adding of repositories to make sure they are prepended as order is much more important in Composer 2, also added a --append flag to `config` to restore the old behavior in the unlikely case this is needed
  * Fixed curl downloader failing on old PHP releases or when using self-signed SSL certificates
  * Fixed Bitbucket API authentication issue

### [2.0.3] 2020-10-28

  * Fixed bug in `outdated` command where dev packages with branch-aliases where always shown as being outdated
  * Fixed issue in lock file interoperability with composer 1.x when using `dev-master as xxx` aliases
  * Fixed new `--locked` option being missing from `outdated` command, for checking outdated packages directly from the lock file
  * Fixed a few debug/error reporting strings

### [2.0.2] 2020-10-25

  * Fixed regression handling `composer show -s` in projects where no version can be guessed from VCS
  * Fixed regression handling partial updates/`require` when a lock file was missing
  * Fixed interop issue with plugins that need to update dist URLs of packages, [see docs](https://getcomposer.org/doc/articles/plugins.md#plugin-modifies-downloads) if you need this

### [2.0.1] 2020-10-24

  * Fixed crash on PHP 8

### [2.0.0] 2020-10-24

  * Fixed proxy handling issues when combined with our new curl-based downloader
  * Fixed solver bug resulting in endless loops in some cases
  * Fixed solver output being extremely long due to learnt rules
  * Fixed solver bug with multi literals
  * Fixed a couple minor regressions

### [2.0.0-RC2] 2020-10-14

  * Breaking: Removed `OperationInterface::getReason` as the data was not accurate
  * Added automatic removal of packages which are not required anymore whenever an update is done, this will purge packages previously left over by partial updates and `require`/`remove`
  * Added shorthand aliases `-w` for `--with-dependencies` and `-W` for `--with-all-dependencies` on `update`/`require`/`remove` commands
  * Added `COMPOSER_DEBUG_EVENTS=1` env var support for plugin authors to figure out which events are triggered when
  * Added `setCustomCacheKey` to `PreFileDownloadEvent` and fixed a cache bug for integrations changing the processed url of package archives
  * Added `Composer\Util\SyncHelper` for plugin authors to deal with async Promises more easily
  * Added `$composer->getLoop()->getHttpDownloader()` to get access to the main HttpDownloader instance in plugins
  * Added a non-zero exit code (2) and warning to `remove` command when a package to be removed could not be removed
  * Added `--apcu-autoloader-prefix` (or `--apcu-prefix` for `dump-autoload` command) flag to let people use apcu autoloading in a deterministic output way if that is needed
  * Fixed version guesser to look at remote branches as well as local ones
  * Lots of minor bug fixes and improvements

### [2.0.0-RC1] 2020-09-10

  * Added more advanced filtering to avoid loading all versions of all referenced packages when resolving dependencies, which should reduce memory usage further in some cases
  * Added support for many new `lib-*` packages in the platform repository and improved version detection for some `ext-*` and `lib-*` packages
  * Added an `--ask` flag to `create-project` command to make Composer prompt for the install dir name, [useful for project install instructions](https://github.com/composer/composer/pull/9181)
  * Added support for tar in artifact repositories
  * Added a `cache-read-only` config option to make the cache usable in read only mode for containers and such
  * Added better error reporting for a few more specific cases
  * Added a new optional `available-package-patterns` attribute for v2-format Composer repositories, see [UPGRADE](UPGRADE-2.0.md) for details
  * Fixed more PHP 8 compatibility issues
  * Lots of minor bug fixes for regressions

### [2.0.0-alpha3] 2020-08-03

  * Breaking: Zip archives loaded by artifact repositories must now have a composer.json on top level, or a max of one folder on top level of the archive
  * Added --no-dev support to `show` and `outdated` commands to skip dev requirements
  * Added support for multiple --repository flags being passed into the `create-project` command, only useful in combination with `--add-repository` to persist them to composer.json
  * Added a new optional `list` API endpoint for v2-format Composer repositories, see [UPGRADE](UPGRADE-2.0.md) for details
  * Fixed `show -a` command not listing anything
  * Fixed solver bug where it ended in a "Reached invalid decision id 0"
  * Fixed updates of git-installed packages on windows
  * Lots of minor bug fixes

### [2.0.0-alpha2] 2020-06-24

  * Added parallel installation of packages (requires OSX/Linux/WSL, and that `unzip` is present in PATH)
  * Added optimization of constraints by compiling them to PHP code, which should reduce CPU time of updates
  * Added handling of Ctrl-C on Windows for PHP 7.4+
  * Added better support for default branch names other than `master`
  * Added --format=summary flag to `license` command
  * Fixed issue in platform check when requiring ext-zend-opcache
  * Fixed inline aliases issues
  * Fixed git integration issue when signatures are set to be shown by default

### [2.0.0-alpha1] 2020-06-03

  * Breaking: This is a major release and while we tried to keep things compatible for most users, you might want to have a look at the [UPGRADE](UPGRADE-2.0.md) guides
  * Many CPU and memory performance improvements
  * The update command is now much more deterministic as it does not take the already installed packages into account
  * Package installation now performs all network operations first before doing any changes on disk, to reduce the chances of ending up with a partially updated vendor dir
  * Partial updates and require/remove are now much faster as they only load the metadata required for the updated packages
  * Added a [platform-check step](doc/07-runtime.md#platform-check) when vendor/autoload.php gets initialized which checks the current PHP version/extensions match what is expected and fails hard otherwise. Can be disabled with the platform-check config option
  * Added a [`Composer\InstalledVersions`](doc/07-runtime.md#installed-versions) class which is autoloaded in every project and lets you check which packages/versions are present at runtime
  * Added a `composer-runtime-api` virtual package which you can require (as e.g. `^2.0`) to ensure things like the InstalledVersions class above are present. It will effectively force people to use Composer 2.x to install your project
  * Added support for parallel downloads of package metadata and zip files, this requires that the curl extension is present and we thus strongly recommend enabling curl
  * Added much clearer dependency resolution error reporting for common error cases
  * Added support for updating to a specific version with partial updates, as well as a [--with flag](doc/03-cli.md#update--u) to pass in temporary constraint overrides
  * Added support for TTY mode on Linux/OSX/WSL so that script handlers now run in interactive mode
  * Added `only`, `exclude` and `canonical` options to all repositories, see [repository priorities](https://getcomposer.org/repoprio) for details
  * Added support for lib-zip platform package
  * Added `pre-operations-exec` event to be fired before the packages get installed/upgraded/removed
  * Added `pre-pool-create` event to be fired before the package pool for the dependency solver is created, which lets you modify the list of packages going in
  * Added `post-file-download` event to be fired after package dist files are downloaded, which lets you do additional checks on the files
  * Added --locked flag to `show` command to see the packages from the composer.lock file
  * Added --unused flag to `remove` command to make sure any packages which are not needed anymore get removed
  * Added --dry-run flag to `require` and `remove` commands
  * Added --no-install flag to `update`, `require` and `remove` commands to disable the install step and only do the update step (composer.lock file update)
  * Added --with-dependencies and --with-all-dependencies flag aliases to `require` and `remove` commands for consistency with `update`
  * Added more info to `vendor/composer/installed.json`, a dev key stores whether dev requirements were installed, and every package now has an install-path key with its install location
  * Added COMPOSER_DISABLE_NETWORK which if set makes Composer do its best to run offline. This can be useful when you have poor connectivity or to do benchmarking without network jitter
  * Added --json and --merge flags to `config` command to allow editing complex `extra.*` values by using json as input
  * Added confirmation prompt when running Composer as superuser in interactive mode
  * Added --no-check-version to `validate` command to remove the warning in case the version is defined
  * Added --ignore-platform-req (without s) to all commands supporting --ignore-platform-reqs, which accepts a package name so you can ignore only specific platform requirements
  * Added support for wildcards (`*`) in classmap autoloader paths
  * Added support for configuring GitLab deploy tokens in addition to private tokens, see [gitlab-token](doc/06-config.md#gitlab-token)
  * Added support for package version guessing for require and init command to take all platform packages into account, not just php version
  * Fixed package ordering when autoloading and especially when loading plugins, to make sure dependencies are loaded before their dependents
  * Fixed suggest output being very spammy, it now is only one line long and shows more rarely
  * Fixed conflict rules like e.g. >=5 from matching dev-master, as it is not normalized to 9999999-dev internally anymore

### [1.10.23] 2021-10-05

  * Security: Fixed command injection vulnerability on Windows (GHSA-frqg-7g38-6gcf / CVE-2021-41116)

### [1.10.22] 2021-04-27

  * Security: Fixed command injection vulnerability in HgDriver/HgDownloader and hardened other VCS drivers and downloaders (GHSA-h5h8-pc6h-jvvx / CVE-2021-29472)

### [1.10.21] 2021-04-01

  * Fixed support for new GitHub OAuth token format
  * Fixed processes silently ignoring the CWD when it does not exist

### [1.10.20] 2021-01-27

  * Fixed exclude-from-classmap causing regex issues when having too many paths
  * Fixed compatibility issue with Symfony 4/5

### [1.10.19] 2020-12-04

  * Fixed regression on PHP 8.0

### [1.10.18] 2020-12-03

  * Allow installation on PHP 8.0

### [1.10.17] 2020-10-30

  * Fixed Bitbucket API authentication issue
  * Fixed parsing of Composer 2 lock files breaking in some rare conditions

### [1.10.16] 2020-10-24

  * Added warning to `validate` command for cases where packages provide/replace a package that they also require
  * Fixed JSON schema validation issue with PHPStorm
  * Fixed symlink handling in `archive` command

### [1.10.15] 2020-10-13

  * Fixed path repo version guessing issue

### [1.10.14] 2020-10-13

  * Fixed version guesser to look at remote branches as well as local ones
  * Fixed path repositories version guessing to handle edge cases where version is different from the VCS-guessed version
  * Fixed COMPOSER env var causing issues when combined with the `global ` command
  * Fixed a few issues dealing with PHP without openssl extension (not recommended at all but sometimes needed for testing)

### [1.10.13] 2020-09-09

  * Fixed regressions with old version validation
  * Fixed invalid root aliases not being reported

### [1.10.12] 2020-09-08

  * Fixed regressions with old version validation

### [1.10.11] 2020-09-08

  * Fixed more PHP 8 compatibility issues
  * Fixed regression in handling of CTRL-C when xdebug is loaded
  * Fixed `status` handling of broken symlinks

### [1.10.10] 2020-08-03

  * Fixed `create-project` not triggering events while installing the root package
  * Fixed PHP 8 compatibility issue
  * Fixed `self-update` to avoid automatically upgrading to the next major version once it becomes stable

### [1.10.9] 2020-07-16

  * Fixed Bitbucket redirect loop when credentials are outdated
  * Fixed GitLab auth prompt wording
  * Fixed `self-update` handling of files requiring admin permissions to write to on Windows (it now does a UAC prompt)
  * Fixed parsing issues in funding.yml files

### [1.10.8] 2020-06-24

  * Fixed compatibility issue with git being configured to show signatures by default
  * Fixed discarding of local changes when updating packages to include untracked files
  * Several minor fixes

### [1.10.7] 2020-06-03

  * Fixed PHP 8 deprecations
  * Fixed detection of pcntl_signal being in disabled_functions when pcntl_async_signal is allowed

### [1.10.6] 2020-05-06

  * Fixed version guessing to take composer-runtime-api and composer-plugin-api requirements into account to avoid selecting packages which require Composer 2
  * Fixed package name validation to allow several dashes following each other
  * Fixed post-status-cmd script not firing when there were no changes to be displayed
  * Fixed composer-runtime-api support on Composer 1.x, the package is now present as 1.0.0
  * Fixed support for composer show --name-only --self
  * Fixed detection of GitLab URLs when handling authentication in some cases

### [1.10.5] 2020-04-10

  * Fixed self-update on PHP <5.6, seriously please upgrade people, it's time
  * Fixed 1.10.2 regression with PATH resolution in scripts

### [1.10.4] 2020-04-09

  * Fixed 1.10.2 regression in path symlinking with absolute path repos

### [1.10.3] 2020-04-09

  * Fixed invalid --2 flag warning in `self-update` when no channel is requested

### [1.10.2] 2020-04-09

  * Added --1 flag to `self-update` command which can be added to automated self-update runs to make sure it won't automatically jump to 2.0 once that is released
  * Fixed path repository symlinks being made relative when the repo url is defined as absolute paths
  * Fixed potential issues when using "composer ..." in scripts and composer/composer was also required in the project
  * Fixed 1.10.0 regression when downloading GitHub archives from non-API URLs
  * Fixed handling of malformed info in fund command
  * Fixed Symfony5 compatibility issues in a few commands

### [1.10.1] 2020-03-13

  * Fixed path repository warning on empty path when using wildcards
  * Fixed superfluous warnings when generating optimized autoloaders

### [1.10.0] 2020-03-10

  * Added `bearer` auth config to authenticate using `Authorization: Bearer <token>` headers
  * Added `plugin-api-version` in composer.lock so third-party tools can know which Composer version was used to generate a lock file
  * Fixed composer fund command and funding info parsing to be more useful
  * Fixed issue where --no-dev autoload generation was excluding some packages which should not have been excluded
  * Fixed 1.10-RC regression in create project's handling of absolute paths

### [1.10.0-RC] 2020-02-14

  * Breaking: `composer global exec ...` now executes the process in the current working directory instead of executing it in the global directory.
  * Warning: Added a warning when class names are being loaded by a PSR-4 or PSR-0 rule only due to classmap optimization, but would not otherwise be autoloadable. Composer 2.0 will stop autoloading these classes so make sure you fix your autoload configs.
  * Added new funding key to composer.json to describe ways your package's maintenance can be funded. This reads info from GitHub's FUNDING.yml by default so better configure it there so it shows on GitHub and Composer/Packagist
  * Added `composer fund` command to show funding info of your dependencies
  * Added support for --format=json output for show command when showing a single package
  * Added support for configuring suggestions using config command, e.g. `composer config suggest.foo/bar some text`
  * Added support for configuring fine-grained preferred-install using config command, e.g. `composer config preferred-install.foo/* dist`
  * Added `@putenv` script handler to set environment variables from composer.json for following scripts
  * Added `lock` option that can be set to false, in which case no composer.lock file will be generated
  * Added --add-repository flag to create-project command which will persist the repo given in --repository into the composer.json of the package being installed
  * Added support for IPv6 addresses in NO_PROXY
  * Added package homepage display in the show command
  * Added debug info about HTTP authentications
  * Added Symfony 5 compatibility
  * Added --fixed flag to require command to make it use a fixed constraint instead of a ^x.y constraint when adding the requirement
  * Fixed exclude-from-classmap matching subsets of directories e.g. foo/ was excluding foobar/
  * Fixed archive command to persist file permissions inside the zip files
  * Fixed init/require command to avoid suggesting packages which are already selected in the search results
  * Fixed create-project UX issues
  * Fixed filemtime for `vendor/composer/*` files is now only changing when the files actually change
  * Fixed issues detecting docker environment with an active open_basedir

### [1.9.3] 2020-02-04

  * Fixed GitHub deprecation of access_token query parameter, now using Authorization header

### [1.9.2] 2020-01-14

  * Fixed minor git driver bugs
  * Fixed schema validation for version field to allow `dev-*` versions too
  * Fixed external processes' output being formatted even though it should not
  * Fixed issue with path repositories when trying to install feature branches

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
  * Fixed Xdebug disabling issue when combined with disable_functions and allow_url_fopen CLI overrides

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
  * Fixed handling of config.platform.php which did not replace other `php-*` package's versions

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
  * Added `COMPOSER_ALLOW_XDEBUG` env var to circumvent the Xdebug-disabling behavior
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
  * Schema: Added a new `~` operator that should be preferred over `>=`, see https://getcomposer.org/doc/01-basic-usage.md#package-versions
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
  * Added support for overriding repositories in the system config (define repositories in `~/.composer/config.json`)
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

[2.2.12]: https://github.com/composer/composer/compare/2.2.11...2.2.12
[2.2.11]: https://github.com/composer/composer/compare/2.2.10...2.2.11
[2.2.10]: https://github.com/composer/composer/compare/2.2.9...2.2.10
[2.2.9]: https://github.com/composer/composer/compare/2.2.8...2.2.9
[2.2.8]: https://github.com/composer/composer/compare/2.2.7...2.2.8
[2.2.7]: https://github.com/composer/composer/compare/2.2.6...2.2.7
[2.2.6]: https://github.com/composer/composer/compare/2.2.5...2.2.6
[2.2.5]: https://github.com/composer/composer/compare/2.2.4...2.2.5
[2.2.4]: https://github.com/composer/composer/compare/2.2.3...2.2.4
[2.2.3]: https://github.com/composer/composer/compare/2.2.2...2.2.3
[2.2.2]: https://github.com/composer/composer/compare/2.2.1...2.2.2
[2.2.1]: https://github.com/composer/composer/compare/2.2.0...2.2.1
[2.2.0]: https://github.com/composer/composer/compare/2.2.0-RC1...2.2.0
[2.2.0-RC1]: https://github.com/composer/composer/compare/2.1.14...2.2.0-RC1
[2.1.14]: https://github.com/composer/composer/compare/2.1.13...2.1.14
[2.1.13]: https://github.com/composer/composer/compare/2.1.12...2.1.13
[2.1.12]: https://github.com/composer/composer/compare/2.1.11...2.1.12
[2.1.11]: https://github.com/composer/composer/compare/2.1.10...2.1.11
[2.1.10]: https://github.com/composer/composer/compare/2.1.9...2.1.10
[2.1.9]: https://github.com/composer/composer/compare/2.1.8...2.1.9
[2.1.8]: https://github.com/composer/composer/compare/2.1.7...2.1.8
[2.1.7]: https://github.com/composer/composer/compare/2.1.6...2.1.7
[2.1.6]: https://github.com/composer/composer/compare/2.1.5...2.1.6
[2.1.5]: https://github.com/composer/composer/compare/2.1.4...2.1.5
[2.1.4]: https://github.com/composer/composer/compare/2.1.3...2.1.4
[2.1.3]: https://github.com/composer/composer/compare/2.1.2...2.1.3
[2.1.2]: https://github.com/composer/composer/compare/2.1.1...2.1.2
[2.1.1]: https://github.com/composer/composer/compare/2.1.0...2.1.1
[2.1.0]: https://github.com/composer/composer/compare/2.1.0-RC1...2.1.0
[2.1.0-RC1]: https://github.com/composer/composer/compare/2.0.14...2.1.0-RC1
[2.0.14]: https://github.com/composer/composer/compare/2.0.13...2.0.14
[2.0.13]: https://github.com/composer/composer/compare/2.0.12...2.0.13
[2.0.12]: https://github.com/composer/composer/compare/2.0.11...2.0.12
[2.0.11]: https://github.com/composer/composer/compare/2.0.10...2.0.11
[2.0.10]: https://github.com/composer/composer/compare/2.0.9...2.0.10
[2.0.9]: https://github.com/composer/composer/compare/2.0.8...2.0.9
[2.0.8]: https://github.com/composer/composer/compare/2.0.7...2.0.8
[2.0.7]: https://github.com/composer/composer/compare/2.0.6...2.0.7
[2.0.6]: https://github.com/composer/composer/compare/2.0.5...2.0.6
[2.0.5]: https://github.com/composer/composer/compare/2.0.4...2.0.5
[2.0.4]: https://github.com/composer/composer/compare/2.0.3...2.0.4
[2.0.3]: https://github.com/composer/composer/compare/2.0.2...2.0.3
[2.0.2]: https://github.com/composer/composer/compare/2.0.1...2.0.2
[2.0.1]: https://github.com/composer/composer/compare/2.0.0...2.0.1
[2.0.0]: https://github.com/composer/composer/compare/2.0.0-RC2...2.0.0
[2.0.0-RC2]: https://github.com/composer/composer/compare/2.0.0-RC1...2.0.0-RC2
[2.0.0-RC1]: https://github.com/composer/composer/compare/2.0.0-alpha3...2.0.0-RC1
[2.0.0-alpha3]: https://github.com/composer/composer/compare/2.0.0-alpha2...2.0.0-alpha3
[2.0.0-alpha2]: https://github.com/composer/composer/compare/2.0.0-alpha1...2.0.0-alpha2
[2.0.0-alpha1]: https://github.com/composer/composer/compare/1.10.7...2.0.0-alpha1
[1.10.23]: https://github.com/composer/composer/compare/1.10.22...1.10.23
[1.10.22]: https://github.com/composer/composer/compare/1.10.21...1.10.22
[1.10.21]: https://github.com/composer/composer/compare/1.10.20...1.10.21
[1.10.20]: https://github.com/composer/composer/compare/1.10.19...1.10.20
[1.10.19]: https://github.com/composer/composer/compare/1.10.18...1.10.19
[1.10.18]: https://github.com/composer/composer/compare/1.10.17...1.10.18
[1.10.17]: https://github.com/composer/composer/compare/1.10.16...1.10.17
[1.10.16]: https://github.com/composer/composer/compare/1.10.15...1.10.16
[1.10.15]: https://github.com/composer/composer/compare/1.10.14...1.10.15
[1.10.14]: https://github.com/composer/composer/compare/1.10.13...1.10.14
[1.10.13]: https://github.com/composer/composer/compare/1.10.12...1.10.13
[1.10.12]: https://github.com/composer/composer/compare/1.10.11...1.10.12
[1.10.11]: https://github.com/composer/composer/compare/1.10.10...1.10.11
[1.10.10]: https://github.com/composer/composer/compare/1.10.9...1.10.10
[1.10.9]: https://github.com/composer/composer/compare/1.10.8...1.10.9
[1.10.8]: https://github.com/composer/composer/compare/1.10.7...1.10.8
[1.10.7]: https://github.com/composer/composer/compare/1.10.6...1.10.7
[1.10.6]: https://github.com/composer/composer/compare/1.10.5...1.10.6
[1.10.5]: https://github.com/composer/composer/compare/1.10.4...1.10.5
[1.10.4]: https://github.com/composer/composer/compare/1.10.3...1.10.4
[1.10.3]: https://github.com/composer/composer/compare/1.10.2...1.10.3
[1.10.2]: https://github.com/composer/composer/compare/1.10.1...1.10.2
[1.10.1]: https://github.com/composer/composer/compare/1.10.0...1.10.1
[1.10.0]: https://github.com/composer/composer/compare/1.10.0-RC...1.10.0
[1.10.0-RC]: https://github.com/composer/composer/compare/1.9.3...1.10.0-RC
[1.9.3]: https://github.com/composer/composer/compare/1.9.2...1.9.3
[1.9.2]: https://github.com/composer/composer/compare/1.9.1...1.9.2
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
