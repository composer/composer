<!--
    tagline: Cache Composer downloads safely in CI and containers
-->

# Caching Composer dependencies

Caching can make repeated Composer installs much faster, especially in CI and container builds. A cache should remain an optimization though: a build must still be correct when the cache is empty.

For most projects, cache Composer's download cache instead of `vendor/`. Composer can then reuse downloaded package archives while `composer install` still installs exactly what `composer.lock` specifies and checks it against the current PHP version and extensions.

## What to cache

Composer's cache contains several independent areas. You can inspect the cache location on the current machine with:

```sh
composer config cache-dir --absolute
```

The most reusable part is the package file cache (`cache-files-dir`), which stores downloaded dist archives. Composer also caches repository metadata and VCS clones. The default cache has built-in garbage collection: unused package files expire after six months and the files cache is limited to 300 MiB unless configured otherwise.

The examples below cache the whole cache directory because that is the simplest setup. Jobs that only run `composer install` from a lock file mostly use the package file cache, so you can cache `cache-files-dir` alone to save cache storage. Jobs that run `composer update` also benefit from the repository metadata cache.

In CI it is often convenient to set `COMPOSER_CACHE_DIR` to a path inside the workspace so the CI provider can cache one predictable directory:

```sh
COMPOSER_CACHE_DIR="$PWD/.composer-cache" composer install --no-interaction --prefer-dist
```

Add such a directory to `.gitignore`, and to `.dockerignore` if you build images from the same checkout, so it does not show up as an untracked change or end up in archives, build contexts and code-analysis runs.

Do not put credentials, `auth.json`, SSH keys, tokens, or other secrets in a shared cache.

## Cache downloads, not installed dependencies

Caching `vendor/` can be tempting because restoring it is fast, but it is also easier to make stale. The installed files depend on more than `composer.lock`: install flags such as `--no-dev` or `--optimize-autoloader`, Composer plugins or scripts that generate files, and changes made inside `vendor/`, which `composer install` does not detect.

A cached Composer download directory has a smaller correctness surface: after restoring it, Composer still reads `composer.lock`, checks the platform requirements, and installs every package into a fresh `vendor/`. For this reason, caching the Composer cache is a good default. Cache `vendor/` only when every job restoring it uses the same install flags and you include those in the cache key.

Always keep `composer.lock` in version control for applications and run `composer install` in CI. A warm cache should save downloads, not replace dependency verification.

## Cache keys

A useful cache key separates incompatible environments while still allowing reuse after dependency changes. Downloaded archives do not depend on the PHP version, so jobs on the same operating system can usually share one download cache.

For download caches, use a fallback key so a changed `composer.lock` can still reuse archives downloaded by earlier builds. For `vendor/` caches, be stricter and include the lock-file hash plus the install flags and anything else that changes the installed files.

Libraries often do not commit `composer.lock` and run `composer update` in CI instead. A key based on the lock-file hash then never changes, so the cache is stored once and never refreshed. Key the cache on `composer.json` plus a value that changes on every run, and fall back to older entries by prefix. On GitHub Actions for example:

```yaml
key: composer-${{ runner.os }}-${{ hashFiles('composer.json') }}-${{ github.run_id }}
restore-keys: |
  composer-${{ runner.os }}-${{ hashFiles('composer.json') }}-
  composer-${{ runner.os }}-
```

If a CI provider allows untrusted pull requests to store caches that may later be restored by trusted branches, treat those cache writes as a trust boundary. Never cache secrets, and restrict cache writes from untrusted jobs according to your CI provider's security model.

## GitHub Actions

For most workflows, [ramsey/composer-install](https://github.com/ramsey/composer-install) is the simplest option. It runs Composer and caches Composer's cache directory by default, so you usually do not need a separate caching step or a custom `COMPOSER_CACHE_DIR`:

```yaml
- uses: shivammathur/setup-php@v2
  with:
    php-version: ${{ matrix.php }}

- uses: ramsey/composer-install@v4
```

If you need explicit control over the cache path or key, use `actions/cache` directly. The following example keeps Composer downloads in a workspace-relative directory and falls back to older caches for the same runner OS:

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
          key: composer-${{ runner.os }}-${{ hashFiles('composer.lock') }}
          restore-keys: |
            composer-${{ runner.os }}-

      - name: Install dependencies
        run: composer install --no-interaction --prefer-dist --no-progress
```

Setting `COMPOSER_CACHE_DIR` on the job rather than on a single step makes every Composer command in the job use the cached directory.

When a workflow runs against untrusted contributions, follow GitHub Actions' cache-security guidance and avoid letting untrusted jobs write caches that trusted branches will later restore.

## GitLab CI/CD

GitLab can derive a cache key from `composer.lock`. A project-local Composer cache keeps the configuration portable across runner images:

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

If you want reuse across lock-file changes, use a broader key or list older keys in `cache:fallback_keys`. Without a `composer.lock` the key falls back to `default` and never changes, see [Cache keys](#cache-keys) for an alternative.

## Bitbucket Pipelines

Bitbucket Pipelines provides a predefined `composer` cache. It always caches `~/.composer/cache`, but Composer uses a different directory in many environments, for example `/tmp/cache` in the official `composer` Docker image or `~/.cache/composer` on systems following the XDG specification. Set `COMPOSER_CACHE_DIR` so both agree, otherwise the cache silently stays empty:

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

A predefined cache is not updated once stored and only expires after a week without use. Use a custom cache definition keyed on `composer.lock` when you want it refreshed on dependency changes, or when you need a different path.

## CircleCI

CircleCI caches are immutable: an entry saved under a key is never updated, so include the `composer.lock` checksum in the key to store a new entry when dependencies change. Restore before installing and save after the install. Keeping the Composer cache in the workspace makes the cached path explicit:

```yaml
steps:
  - checkout
  - restore_cache:
      keys:
        - composer-v1-{{ arch }}-{{ checksum "composer.lock" }}
        - composer-v1-{{ arch }}-
  - run:
      name: Install dependencies
      command: |
        export COMPOSER_CACHE_DIR="$PWD/.composer-cache"
        composer install --no-interaction --prefer-dist --no-progress
  - save_cache:
      key: composer-v1-{{ arch }}-{{ checksum "composer.lock" }}
      paths:
        - .composer-cache
```

Bump the manual prefix (`composer-v1`) when you intentionally need to invalidate all previously stored caches.

## Docker and BuildKit

For Docker builds, BuildKit cache mounts let package downloads survive between builds without copying Composer's cache into the final image:

```dockerfile
# syntax=docker/dockerfile:1
FROM composer:2 AS build
WORKDIR /app
COPY . .
RUN --mount=type=cache,target=/tmp/composer-cache \
    COMPOSER_CACHE_DIR=/tmp/composer-cache \
    composer install --no-interaction --prefer-dist --no-progress
```

For projects whose Composer scripts do not require the full application source, you can improve Docker layer reuse further by copying `composer.json` and `composer.lock` before the rest of the source. If scripts or plugins depend on application files, preserve the ordering your project requires rather than disabling them merely to make the cache hit.

## Diagnosing cache problems

When a cached build behaves differently from a cold build, first rerun it with an empty cache. Composer provides commands to inspect and clear its own cache:

```sh
composer config cache-dir --absolute
composer clear-cache
```

Also inspect the CI cache key and the environment that produced the entry. Common causes of stale `vendor/` caches are install flags, Composer plugins or scripts that generate files, and files modified inside `vendor/`, when these are not represented in the key.

A reliable cache has three properties: deleting it never breaks the build, restoring it never supplies secrets, and its key prevents incompatible build environments from sharing installed state.

## Further reading

- [Composer configuration: cache settings](../06-config.md#cache-dir)
- [Composer CLI: `clear-cache`](../03-cli.md#clear-cache-clearcache-cc)
- [GitHub Actions dependency caching](https://docs.github.com/actions/reference/workflows-and-actions/dependency-caching)
- [ramsey/composer-install](https://github.com/ramsey/composer-install)
- [GitLab CI/CD cache examples](https://docs.gitlab.com/ci/caching/examples/)
- [Bitbucket Pipelines dependency caches](https://support.atlassian.com/bitbucket-cloud/docs/cache-dependencies/)
- [CircleCI dependency caching](https://circleci.com/docs/guides/optimize/caching/)
