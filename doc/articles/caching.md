<!--
    tagline: Cache Composer downloads safely in CI and containers
-->

# Caching Composer dependencies

Caching can make repeated Composer installs much faster, especially in CI and container builds. A cache should remain an optimization though: a build must still be correct when the cache is empty.

For most projects, cache Composer's download cache instead of `vendor/`. Composer can then reuse downloaded package archives while `composer install` still reconstructs `vendor/` from `composer.lock` for the current PHP version, extensions, Composer version, and platform.

## What to cache

Composer's cache contains several independent areas. You can inspect the cache location on the current machine with:

```sh
composer config cache-dir --absolute
```

The most reusable part is the package file cache (`cache-files-dir`), which stores downloaded dist archives. Composer also caches repository metadata and VCS clones. The default cache has built-in garbage collection: unused package files expire after six months and the files cache is limited to 300 MiB unless configured otherwise.

In CI it is often convenient to set `COMPOSER_CACHE_DIR` to a path inside the workspace so the CI provider can cache one predictable directory:

```sh
COMPOSER_CACHE_DIR="$PWD/.composer-cache" composer install --no-interaction --prefer-dist
```

Do not put credentials, `auth.json`, SSH keys, tokens, or other secrets in a shared cache.

## Cache downloads, not installed dependencies

Caching `vendor/` can be tempting because restoring it is fast, but it is also easier to make stale. Installed dependencies can vary with PHP versions, enabled extensions, operating systems, CPU architectures, Composer plugins, and install flags such as `--no-dev`.

A cached Composer download directory has a smaller correctness surface: after restoring it, Composer still reads `composer.lock`, resolves the current platform requirements, and performs the install. For this reason, caching the Composer cache is a good default. Cache `vendor/` only when you control all relevant platform inputs and include them in the cache key.

Always keep `composer.lock` in version control for applications and run `composer install` in CI. A warm cache should save downloads, not replace dependency verification.

## Cache keys

A useful cache key separates incompatible environments while still allowing reuse after dependency changes. Include inputs that materially affect the cache or installed result, such as the operating system and PHP version when appropriate.

For download caches, use a fallback key so a changed `composer.lock` can still reuse archives downloaded by earlier builds. For `vendor/` caches, be stricter and include the lock-file hash plus relevant platform inputs.

If a CI provider allows untrusted pull requests to restore caches created by trusted branches, treat restored cache contents as untrusted input. Never cache secrets, and restrict cache writes from untrusted jobs according to your CI provider's security model.

## GitHub Actions

The following example keeps Composer downloads in a workspace-relative directory and falls back to older caches for the same runner OS and PHP version:

```yaml
- uses: actions/cache@v6
  with:
    path: .composer-cache
    key: composer-${{ runner.os }}-${{ matrix.php }}-${{ hashFiles('composer.lock') }}
    restore-keys: |
      composer-${{ runner.os }}-${{ matrix.php }}-

- name: Install dependencies
  env:
    COMPOSER_CACHE_DIR: ${{ github.workspace }}/.composer-cache
  run: composer install --no-interaction --prefer-dist --no-progress
```

When a workflow runs against untrusted contributions, follow GitHub Actions' cache-security guidance and avoid putting credentials or executable project state in the cache.

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

If you want reuse across lock-file changes, use a broader key or GitLab's fallback-key facilities while keeping incompatible runner/platform variants separated.

## Bitbucket Pipelines

Bitbucket Pipelines provides a predefined `composer` cache for Composer's normal cache directory:

```yaml
pipelines:
  default:
    - step:
        caches:
          - composer
        script:
          - composer install --no-interaction --prefer-dist --no-progress
```

Use a custom cache definition instead when you need a different path or cache-key strategy.

## CircleCI

CircleCI cache keys are immutable, so restore before installing and save only after a successful install. Keeping the Composer cache in the workspace makes the cached path explicit:

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

Also inspect the CI cache key and the environment that produced the entry. Common causes of stale installed-dependency caches include changes to PHP, extensions, operating system, architecture, Composer plugins, or install flags that were not represented in the key.

A reliable cache has three properties: deleting it never breaks the build, restoring it never supplies secrets, and its key prevents incompatible build environments from sharing installed state.

## Further reading

- [Composer configuration: cache settings](../06-config.md#cache-dir)
- [Composer CLI: `clear-cache`](../03-cli.md#clear-cache-clearcache-cc)
- [GitHub Actions dependency caching](https://docs.github.com/actions/reference/workflows-and-actions/dependency-caching)
- [GitLab CI/CD cache examples](https://docs.gitlab.com/ci/caching/examples/)
- [Bitbucket Pipelines dependency caches](https://support.atlassian.com/bitbucket-cloud/docs/cache-dependencies/)
- [CircleCI dependency caching](https://circleci.com/docs/guides/optimize/caching/)
