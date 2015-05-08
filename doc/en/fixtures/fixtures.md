`Composer` type repository fixtures
=======================

This directory contains some examples of what `composer` type repositories can look like. They serve as illustrating examples accompanying the docs, but can also be used as (initial) fixtures for tests.

* `repo-composer-plain` is a simple, plain `packages.json` file
* `repo-composer-with-includes` uses the `includes` mechanism
* `repo-composer-with-providers` uses the `providers` mechanism

Sample Packages used in these fixtures
-------

All these repositories contain the following packages.

* `foo/bar` versions 1.0.0, 1.0.1 and 1.1.0; dev-default and 1.0.x-dev branches. On dev-default and in 1.1.0, `bar/baz` ~1.0 is required.
* `qux/quux` only has a dev-default branch. It `replace`s `gar/nix`.
* `gar/nix` has a 1.0.0 version and a dev-default branch. It is being replaced by `qux/quux`.
* `bar/baz` has a 1.0.0 version and 1.0.x-dev as well as dev-default branches. Additionally, 1.1.x-dev is a branch alias for dev-default.


