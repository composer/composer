#
<!--
    tagline: Configure which packages are found in which repositories
-->

# Repository priorities

## Canonical repositories

When Composer resolves dependencies, it will look up a given package in the
topmost repository. If that repository does not contain the package, it
goes on to the next one, until one repository contains it and the process ends.

Canonical repositories are better for a few reasons:

- Performance wise, it is more efficient to stop looking for a package once it
  has been found somewhere. It also avoids loading duplicate packages in case
  the same package is present in several of your repositories.
- Security wise, it is safer to treat them canonically as it means that packages you
  expect to come from your most important repositories will never be loaded from
  another repository instead. Let's
  say you have a private repository which is not canonical, and you require your
  private package `foo/bar ^2.0` for example. Now if someone publishes
  `foo/bar 2.999` to packagist.org, suddenly Composer will pick that package as it
  has a higher version than your latest release (say 2.4.3), and you end up installing
  something you may not have meant to. However, if the private repository is canonical,
  that 2.999 version from packagist.org will not be considered at all.

There are however a few cases where you may want to specifically load some packages
from a given repository, but not all. Or you may want a given repository to not be
canonical, and to be only preferred if it has higher package versions than the
repositories defined below.

## Default behavior

By default in Composer 2.x all repositories are canonical. Composer 1.x treated
all repositories as non-canonical.

Another default is that the packagist.org repository is always added implicitly
as the last repository, unless you [disable it](../05-repositories.md#disabling-packagist-org).

## Making repositories non-canonical

You can add the canonical option to any repository to disable this default behavior
and make sure Composer keeps looking in other repositories, even if that repository
contains a given package.

```json
{
    "repositories": [
        {
            "type": "composer",
            "url": "https://example.org",
            "canonical": false
        }
    ]
}
```

## Filtering packages

You can also filter packages which a repository will be able to load, either by
selecting which ones you want, or by excluding those you do not want.

For example here we want to pick only the package `foo/bar` and all the packages from
`some-vendor/` from this Composer repository.

```json
{
    "repositories": [
        {
            "type": "composer",
            "url": "https://example.org",
            "only": ["foo/bar", "some-vendor/*"]
        }
    ]
}
```

And in this other example we exclude `toy/package` from a repository, which
we may not want to load in this project.

```json
{
    "repositories": [
        {
            "type": "composer",
            "url": "https://example.org",
            "exclude": ["toy/package"]
        }
    ]
}
```

Both `only` and `exclude` should be arrays of package names, which can also
contain wildcards (`*`), which will match any character.
