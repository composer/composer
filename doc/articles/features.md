<!--
    tagline: Ship optional dependencies as named, resolvable groups
-->

# Features

## Why features exist

A lot of libraries have parts that only some of their users need: a Monolog
handler, a Symfony bridge, a faster serializer. Putting those extra packages in
[`require`](../04-schema.md#package-links) makes everyone pay for them, so the
usual recipe is a pile of workarounds: [`suggest`](../04-schema.md#suggest) so
users hear about it, `require-dev` so your own tests can run, a `conflict` entry
with roughly the *inverse* constraint to block versions you do not support — and
then keeping that inverted constraint in sync by hand.

It gets worse when one integration needs several packages: users have to require
them all, and forgetting one, or missing a new one added in a later release,
silently disables the feature. That is why many maintainers end up publishing
separate bridge packages, which in practice means a monorepo plus a
git-splitting pipeline.

A **feature** is a named group of requirements that a package offers and that is
only installed when someone asks for it by name. Enabled features are resolved
by the dependency solver like any other requirement, so they take part in
version selection and end up in `composer.lock`.

## Offering a feature

Any package can declare features, with the
[`features`](../04-schema.md#features) property:

```json
{
    "name": "acme/mailer",
    "require": {
        "php": "^8.1"
    },
    "features": {
        "logging": {
            "description": "Log every delivery attempt through a PSR-3 logger",
            "require": {
                "psr/log": "^3.0"
            }
        },
        "twig": {
            "description": "Render mail bodies with Twig templates",
            "require": {
                "twig/twig": "^3.8"
            }
        }
    }
}
```

A feature accepts a `description`, shown by
[`composer show`](../03-cli.md#show), and a `require` object with the same syntax
as the root `require`.

## Asking for a feature of a dependency

Enable a feature of one of your dependencies with
[`require-features`](../04-schema.md#require-features):

```json
{
    "require": {
        "acme/mailer": "^2.0"
    },
    "require-features": {
        "acme/mailer": ["logging"]
    }
}
```

`composer update` now installs `acme/mailer` **and** `psr/log`.

The easiest way to write that is to let `composer require` do it:

```shell
php composer.phar require acme/mailer:^2.0 --feature logging
```

When several packages are required at once the short form is ambiguous, so name
the package: `--feature acme/mailer:logging`. `composer remove acme/mailer` drops
the `require-features` entry along with the `require` one.

Any package can use `require-features`, not just the root one, and the request is
honoured transitively.

## Your own project's features

A root `composer.json` can declare features too. The point is to develop and test
your library against each of them without committing a different `composer.json`
per job. You select them with `--self-feature`:

```shell
php composer.phar update --self-feature logging --self-feature twig
```

Because nothing is resolved unless you ask for it, **features are allowed to
conflict with each other**. A bundle can offer a `symfony6` feature requiring
`symfony/framework-bundle: ^6.4` and a `symfony7` feature requiring `^7.0`; they
can never be installed together, and Composer never tries to. A CI matrix is then
one job per combination you support:

```shell
php composer.phar update                    # no feature at all
php composer.phar update --self-feature symfony6
php composer.phar update --self-feature symfony7 --self-feature twig
php composer.phar update --self-feature symfony6 --self-feature symfony7   # will throw an error
```

The selected set is written to `composer.lock`, so a plain `composer install`
reuses it and CI does not have to repeat the flags. It does have to be repeated
on every `composer update`, though: an update without `--self-feature` re-resolves
with no root feature enabled. Passing `--self-feature` to `composer install` when
it disagrees with the lock file stops with exit code 4 rather than installing
something that does not match.

## How features affect version resolution

A required feature constrains which versions are installable: a version that does
not offer it cannot be selected. If `acme/mailer` only gained `logging` in 2.3.0,
then `"acme/mailer": "^2.0"` with that feature required will not resolve to
2.0.0, even though it satisfies the constraint.

If no version offers the feature, resolution fails:

```
  Problem 1
    - Root composer.json requires acme/mailer ^2.0 -> satisfiable by acme/mailer[2.0.0].
    - Root composer.json requires feature "logging" for acme/mailer -> could not be found.
```

Two mistakes are only warned about and then ignored, since they cannot make the
install wrong: asking for a root feature that is not declared (usually a typo,
dropped before it reaches the lock file), and a `require-features` entry pointing
at a package that nothing requires.

## Checking features at runtime

Your code can ask whether it was installed with a given feature enabled:

```php
if (\Composer\InstalledVersions::hasFeature('acme/mailer', 'logging')) {
    $mailer->setLogger($logger);
}
```

This is true only when something explicitly requested the feature, so it reflects
intent rather than the package merely happening to be installed. It works for
your own project's features too, using the root package name.

`hasFeature()` only exists in recent Composer versions, and the
`InstalledVersions` class in `vendor/` is written by whichever Composer ran the
install, so a library supporting older versions should guard the call with
`method_exists(\Composer\InstalledVersions::class, 'hasFeature')`.
