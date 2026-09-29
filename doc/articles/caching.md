<!--
    tagline: Cache Composer downloads safely in CI and containers
-->

# Caching Composer dependencies

Composer keeps a cache of everything it downloads on the machine it runs on, so that later runs on the same machine do not have to download it again. CI jobs and container builds usually start on a fresh machine or in a fresh container though, so this cache is empty at the start of every build unless you persist it between builds, for example with your CI provider's cache feature or a Docker BuildKit cache mount.

This article explains which parts of Composer's cache are worth persisting between builds and how to do so safely. Two kinds of cache are involved:

- the **Composer cache**: the directory in which Composer itself stores downloaded files, see [`cache-dir`](../06-config.md#cache-dir);
- the **CI cache**: a feature of your CI provider that saves a directory at the end of a job under a **cache key**, and restores it at the start of a later job that asks for the same (or a matching) key.

Persisting the Composer cache between builds should remain an optimization: a build must still produce the same result when the cache is empty.

For most projects, persist the Composer cache rather than the installed `vendor/` directory. Composer can then reuse previously downloaded package archives instead of downloading them again, while `composer install` still installs exactly the versions `composer.lock` specifies and still checks that the PHP version and extensions of the build environment satisfy the requirements of those packages.

## What to cache

The Composer cache consists of several parts, each stored in its own subdirectory of the cache directory. You can print the location of the cache directory on the current machine with:

```sh
composer config cache-dir --absolute
```

The parts are:

- the **files cache** ([`cache-files-dir`](../06-config.md#cache-files-dir)), which stores the dist archives (zip, tar, ...) of packages Composer has downloaded;
- the **repository metadata cache** ([`cache-repo-dir`](../06-config.md#cache-repo-dir)), which stores the package metadata Composer fetched from repositories like Packagist.org to resolve dependencies;
- the **VCS cache** ([`cache-vcs-dir`](../06-config.md#cache-vcs-dir)), which stores clones of VCS repositories, used to read metadata from `vcs` repositories and to install packages from source.

The files cache is the part that saves the most time when persisted between builds, because `composer install` extracts every package from its dist archive, and the archives for a given `composer.lock` are the same in every build. Composer automatically removes old entries from the files cache: archives unused for six months are deleted, and the files cache is limited to 300 MiB, see [`cache-files-ttl`](../06-config.md#cache-files-ttl) and [`cache-files-maxsize`](../06-config.md#cache-files-maxsize). The repository metadata cache and the VCS cache are only cleaned up when you run `composer clear-cache --gc`, which you can do before the CI cache is saved to keep it from growing indefinitely.

The CI examples below persist the entire Composer cache directory, because that is the simplest setup. A job that only runs `composer install` with a `composer.lock` mainly reads from the files cache (and the VCS cache for packages installed from source), so if CI cache storage is limited you can persist only `cache-files-dir`. A job that runs `composer update` also benefits from the repository metadata cache, because Composer then has to load package metadata to resolve dependencies.

Many CI providers can only save directories inside the job's working directory, while Composer's default cache directory is in the user's home directory. You can set the `COMPOSER_CACHE_DIR` environment variable to move the Composer cache into the working directory, so the CI cache can save it from a known path:

```sh
COMPOSER_CACHE_DIR="$PWD/.composer-cache" composer install --no-interaction --prefer-dist
```

Add such a cache directory inside your project to `.gitignore`, and to `.dockerignore` if you build images from the same checkout, so it does not show up as an untracked change or ends up in archives, Docker build contexts and code-analysis runs.

A CI cache is usually shared between jobs, and often between branches, so do not add credentials, `auth.json`, SSH keys, tokens, or other secrets to the paths it saves. In particular, persist the Composer cache directory rather than the whole [`COMPOSER_HOME`](../03-cli.md#composer-home) directory, which can contain `auth.json`.

## Cache downloads, not installed dependencies

Persisting `vendor/` in the CI cache can be tempting because restoring it lets you skip `composer install`, but it is often not faster: `vendor/` usually contains many small files, and on most CI systems saving and restoring many small files takes longer than saving and restoring the few larger archives in the Composer cache. A restored `vendor/` can also easily differ from what a fresh install in the current job would produce. Its contents depend on more than `composer.lock`: on install flags such as `--no-dev` or `--optimize-autoloader`, on files generated by Composer plugins or scripts, and on any changes made to files inside `vendor/`, which `composer install` does not detect or undo.

A restored Composer cache cannot cause these problems: it only supplies downloaded archives, and Composer still reads `composer.lock`, checks the platform requirements, and installs every package into `vendor/` as it would without the cache. This makes persisting the Composer cache a good default. Persist `vendor/` only when every job restoring it uses the same install flags, PHP version and PHP extensions, and you include all of these in the cache key.

Applications should always commit `composer.lock` to version control and run `composer install` in CI. A restored Composer cache should only make that install faster by avoiding downloads, not replace it.

## Cache keys

A CI cache stores each saved directory as an entry under a cache key, and restores the entry whose key matches the key a later job requests. A good key keeps builds from restoring an entry created by an incompatible build environment, while still letting builds reuse entries created before a dependency change.

Dist archives in the files cache are the same regardless of the PHP version or operating system they were downloaded on, so all jobs of a project, such as the jobs of a PHP version matrix, can usually share one Composer cache entry.

When persisting the Composer cache, configure fallback keys (called `restore-keys` on GitHub Actions and `fallback_keys` on GitLab), so that when no entry matches the key of a changed `composer.lock`, the CI cache restores the most recent entry of an earlier `composer.lock` instead. Most archives will still be the same, so Composer only needs to download the packages that changed.

When persisting `vendor/`, do not use fallback keys, and include the hash of `composer.lock`, the install flags, the PHP version, and the list of enabled PHP extensions in the key. `composer install` checks the platform requirements of the locked packages against the PHP version and extensions (unless you use `--ignore-platform-reqs`), and a `vendor/` restored from a different environment would skip that check.

Libraries often do not commit `composer.lock` and run `composer update` in CI instead. A key based on the hash of `composer.lock` is then the same in every run, because the file does not exist when the key is computed. Most CI providers do not overwrite an existing entry, so the Composer cache is saved once and never updated with newer package versions. Instead, build the key from the hash of `composer.json` plus a value that is different on every run, and use prefixes of that key as fallback keys, so every run restores the most recent entry and saves a new one. On GitHub Actions for example:

```yaml
key: composer-${{ hashFiles('composer.json') }}-${{ github.run_id }}
restore-keys: |
  composer-${{ hashFiles('composer.json') }}-
  composer-
```

Some CI providers let jobs for pull requests from untrusted contributors, such as forks, save CI cache entries that jobs on trusted branches later restore. Such a job could save a Composer cache containing modified package archives, which a later trusted build would then install. Do not let untrusted jobs save cache entries that trusted jobs restore, following your CI provider's security documentation, and never put secrets in a CI cache.

## GitHub Actions

For most workflows, [ramsey/composer-install](https://github.com/ramsey/composer-install) is the simplest option. It runs `composer install` and persists the Composer cache directory in the CI cache by default, so you usually do not need a separate caching step or a custom `COMPOSER_CACHE_DIR`:

```yaml
- uses: shivammathur/setup-php@v2
  with:
    php-version: ${{ matrix.php }}

- uses: ramsey/composer-install@v4
```

If you need explicit control over the cached path or the key, use `actions/cache` directly. The following example moves the Composer cache into the workspace, saves it under a key based on `composer.lock`, and restores the most recent entry starting with `composer-` when no entry matches the current `composer.lock`:

```yaml
jobs:
  tests:
    runs-on: ubuntu-latest
    env:
      COMPOSER_CACHE_DIR: ${{ github.workspace }}/.composer-cache
    steps:
      - uses: actions/checkout@v7

      - uses: actions/cache@v6
        with:
          path: .composer-cache
          key: composer-${{ hashFiles('composer.lock') }}
          restore-keys: |
            composer-

      - name: Install dependencies
        run: composer install --no-interaction --prefer-dist --no-progress
```

Setting `COMPOSER_CACHE_DIR` on the job rather than on a single step makes every Composer command in the job use the persisted directory.

When a workflow runs for pull requests from forks, follow GitHub Actions' cache-security guidance, see [Cache keys](#cache-keys), so that these jobs cannot save cache entries that jobs on trusted branches later restore.

## GitLab CI/CD

GitLab can compute the cache key from the contents of `composer.lock`. GitLab only caches paths inside the project directory, so the example moves the Composer cache there:

```yaml
variables:
  COMPOSER_CACHE_DIR: "$CI_PROJECT_DIR/.composer-cache"

default:
  cache:
    key:
      files:
        - composer.lock
    paths:
      - .composer-cache/
  before_script:
    - composer install --no-interaction --prefer-dist --no-progress
```

With this key, a changed `composer.lock` starts with an empty Composer cache. To reuse the archives of an earlier `composer.lock`, use a key that does not change with `composer.lock`, or list older keys in `cache:fallback_keys`. If the project has no `composer.lock`, GitLab uses the key `default`, which never changes, see [Cache keys](#cache-keys) for an alternative.

## Bitbucket Pipelines

Bitbucket Pipelines provides a predefined `composer` cache. It always saves `~/.composer/cache`, but Composer uses a different cache directory in many environments, for example `/tmp/cache` in the official `composer` Docker image or `~/.cache/composer` on systems following the XDG specification. Set `COMPOSER_CACHE_DIR` to `~/.composer/cache`, otherwise Bitbucket saves an empty directory and Composer downloads everything again in every build, without any error:

```yaml
pipelines:
  default:
    - step:
        caches:
          - composer
        script:
          - export COMPOSER_CACHE_DIR="$HOME/.composer/cache"
          - composer install --no-interaction --prefer-dist --no-progress
```

Once saved, a predefined cache entry is not updated, and it is only deleted after a week without use, so packages added to `composer.lock` in the meantime are downloaded in every build. Define a custom cache with a key based on `composer.lock` when you want a new entry saved whenever dependencies change, or when you need a different path.

## CircleCI

CircleCI cache entries are immutable: once an entry is saved under a key, it is never updated. Include the checksum of `composer.lock` in the key, so a new entry is saved when dependencies change. Restore the cache before running Composer and save it afterwards. The example moves the Composer cache into the working directory, so the same relative path can be used in `save_cache`:

```yaml
steps:
  - checkout
  - restore_cache:
      keys:
        - composer-v1-{{ checksum "composer.lock" }}
        - composer-v1-
  - run:
      name: Install dependencies
      command: |
        export COMPOSER_CACHE_DIR="$PWD/.composer-cache"
        composer install --no-interaction --prefer-dist --no-progress
  - save_cache:
      key: composer-v1-{{ checksum "composer.lock" }}
      paths:
        - .composer-cache
```

To discard all previously saved entries, for example after a corrupted cache, change the `v1` in the key prefix to `v2` in both places.

## Docker and BuildKit

In Docker builds, a BuildKit cache mount lets the Composer cache persist from one build to the next, without being copied into the resulting image:

```dockerfile
# syntax=docker/dockerfile:1
FROM php:8.4-cli
RUN apt-get update && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /app
COPY . .
RUN --mount=type=bind,from=composer/composer:2-bin,source=/composer,target=/usr/bin/composer \
    --mount=type=cache,target=/tmp/composer-cache \
    COMPOSER_CACHE_DIR=/tmp/composer-cache \
    composer install --no-interaction --prefer-dist --no-progress
```

Run `composer install` in an image with the same PHP version and extensions as the image your application runs in. This example does so by installing in the application image itself and mounting the Composer binary from the [`composer/composer:2-bin` image](../00-intro.md#docker-image) only for the duration of the install step, so the Composer binary does not end up in the resulting image. Do not run the install in the full `composer` or `composer/composer` images instead: they ship the latest PHP version with only a few extensions, so `composer install` would fail its platform requirement checks for many projects, or produce a `vendor/` directory meant for a different PHP environment than the one your application runs in.

BuildKit keeps cache mounts on the builder that ran the build. They are not included in exported build caches such as `--cache-to`, so on CI runners that start fresh for every job the cache mount is empty in every build, unless you use a persistent builder or a tool that saves and restores cache mounts, like [buildkit-cache-dance](https://github.com/reproducible-containers/buildkit-cache-dance).

Exclude `vendor/` and any Composer cache directory inside your project in `.dockerignore`, so `COPY . .` does not copy them into the image.

Independently of the Composer cache, Docker reuses the result of a build step (a layer) as long as the files copied before it did not change. With `COPY . .` before `composer install`, any change to your application's source therefore reruns the install. If your Composer scripts and plugins do not need the application source, you can copy only `composer.json` and `composer.lock` first, install, and copy the rest of the source afterwards, so the install step is only rerun when dependencies change. As the autoloader cannot be generated before the source is copied, generate it in a separate step:

```dockerfile
COPY composer.json composer.lock ./
RUN --mount=type=bind,from=composer/composer:2-bin,source=/composer,target=/usr/bin/composer \
    --mount=type=cache,target=/tmp/composer-cache \
    COMPOSER_CACHE_DIR=/tmp/composer-cache \
    composer install --no-interaction --prefer-dist --no-progress --no-autoloader
COPY . .
RUN --mount=type=bind,from=composer/composer:2-bin,source=/composer,target=/usr/bin/composer \
    composer dump-autoload --optimize
```

Every `RUN` step that calls `composer`, like the `composer dump-autoload` step above, needs the bind mount, because the Composer binary is only available while it is mounted.

If your scripts or plugins do need application files, keep copying the full source before `composer install`. Do not disable scripts or plugins with `--no-scripts` or `--no-plugins` only to get more layer reuse, as the resulting `vendor/` directory would be missing whatever they generate.

## Diagnosing cache problems

When a build that restored a cache behaves differently from a build without one, first run it again without restoring the cache, for example by changing the cache key, to find out whether the cache is the cause at all. Locally, you can find and empty the Composer cache with these commands:

```sh
composer config cache-dir --absolute
composer clear-cache
```

In CI, also check which key the restored entry was saved under, and which job, branch and build environment saved it. A restored `vendor/` directory is most commonly stale because the key did not account for install flags, files generated by Composer plugins or scripts, or files modified inside `vendor/`.

A reliable cache setup has three properties: the build still works when no cache entry is restored, a restored entry never supplies secrets, and the key prevents a build from restoring a `vendor/` directory installed for a different build environment.

## Further reading

- [Composer configuration: cache settings](../06-config.md#cache-dir)
- [Composer CLI: `clear-cache`](../03-cli.md#clear-cache-clearcache-cc)
- [GitHub Actions dependency caching](https://docs.github.com/actions/reference/workflows-and-actions/dependency-caching)
- [ramsey/composer-install](https://github.com/ramsey/composer-install)
- [GitLab CI/CD cache examples](https://docs.gitlab.com/ci/caching/examples/)
- [Bitbucket Pipelines dependency caches](https://support.atlassian.com/bitbucket-cloud/docs/cache-dependencies/)
- [CircleCI dependency caching](https://circleci.com/docs/guides/optimize/caching/)
