<!--
    tagline: Alias branch names to versions
-->
# Aliases

## Why aliases?

When you are using a VCS repository, you will only get comparable versions for
branches that look like versions, such as `2.0`. For your `master` branch, you
will get a `dev-master` version. For your `bugfix` branch, you will get a
`dev-bugfix` version.

If your `master` branch is used to tag releases of the `1.0` development line,
i.e. `1.0.1`, `1.0.2`, `1.0.3`, etc., any package depending on it will
probably require version `1.0.*`.

If anyone wants to require the latest `dev-master`, they have a problem: Other
packages may require `1.0.*`, so requiring that dev version will lead to
conflicts, since `dev-master` does not match the `1.0.*` constraint.

Enter aliases.

## Branch alias

The `dev-master` branch is one in your main VCS repo. It is rather common that
someone will want the latest master dev version. Thus, Composer allows you to
alias your `dev-master` branch to a `1.0-dev` version. It is done by
specifying a `branch-alias` field under `extra` in `composer.json`:

    {
        "extra": {
            "branch-alias": {
                "dev-master": "1.0-dev"
            }
        }
    }

The branch version must begin with `dev-` (non-comparable version), the alias
must be a comparable dev version. The `branch-alias` must be present on the
branch that it references. For `dev-master`, you need to commit it on the
`master` branch.

As a result, you can now require `1.0.*` and it will happily install
`dev-master` for you.

## Require inline alias

Branch aliases are great for aliasing main development lines. But in order to
use them you need to have control over the source repository, and you need to
commit changes to version control.

This is not really fun when you just want to try a bugfix of some library that
is a dependency of your local project.

For this reason, you can alias packages in your `require` field. Let's say you
found a bug in the `monolog/monolog` package. You cloned Monolog on GitHub and
fixed the issue in a branch named `bugfix`. Now you want to install that
version of monolog in your local project.

Just add this to your project's root `composer.json`:

    {
        "repositories": [
            {
                "type": "vcs",
                "url": "https://github.com/you/monolog"
            }
        ],
        "require": {
            "monolog/monolog": "dev-bugfix as 1.0-dev"
        }
    }

That will fetch the `dev-bugfix` version of `monolog/monolog` from your GitHub
and alias it to `1.0-dev`.

> **Note:** If a package with inline aliases is required, the alias (right of
> the `as`) is used as the version constraint. The part left of the `as` is
> discarded. As a consequence, if A requires B and B requires `monolog/monolog`
> version `dev-bugfix as 1.0-dev`, installing A will make B require `1.0-dev`,
> which may exist as a branch alias or an actual `1.0` branch. If it does not,
> it must be re-inline-aliased in A's `composer.json`.
