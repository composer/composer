<!--
    tagline: Common mistakes and misconceptions
-->

# Pitfalls

## I have a dependency which contains a "repositories" definition in its composer.json, but it seems to be ignored.

The [`repositories`](04-schema.md#repositories) configuration property is defined as [root-only]
(04-schema.md#root-package). It is not inherited. You can read more about the reasons behind this in the "[why can't
composer load repositories recursively?](articles/why-can't-composer-load-repositories-recursively.md)" article.
The simplest work-around to this limitation, is moving or duplicating the `repositories` definition to your root
composer.json.

## I have locked a dependency to a specific commit but get unexpected results.

While Composer supports locking dependencies to a specific commit using the `#commit-ref` syntax, there are certain
caveats that one should take into account. The most important one is [documented](04-schema.md#package-links), but
frequently overlooked:

> **Note:** While this is convenient at times, it should not be how you use
> packages in the long term because it comes with a technical limitation. The
> composer.json metadata will still be read from the branch name you specify
> before the hash. Because of that in some cases it will not be a practical
> workaround, and you should always try to switch to tagged releases as soon
> as you can.

There is no simple work-around to this limitation. It is therefor strongly recommended that you do not use it.
