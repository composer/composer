<!--
    tagline: On gracefully resolving conflicts while merging
-->

# Resolving merge conflicts

When working as a team on the same Composer project, you will eventually run into a scenario
where multiple people added, updated or removed something in the `composer.json` and
`composer.lock` files in multiple branches. When those branches are eventually merged
together, you will get merge conflicts. Resolving these merge conflicts is not as straight
forward as on other files, especially not regarding the `composer.lock` file.

> **Note:** It might not immediately be obvious why text based merging is not possible for
> lock files, so let's imagine the following example where we want to merge two branches;
>
> - Branch 1 has added package A which requires package B. Package B is locked at version `1.0.0`.
> - Branch 2 has added package C which conflicts with all versions below `1.2.0` of package B.
>
> A text based merge would result in package A version `1.0.0`, package B version `1.0.0`
> and package C version `1.0.0`. This is an invalid result, as the conflict of package C
> was not considered and would require an upgrade of package B.

## 1. Reapplying changes

The safest method to merge Composer files is to accept the version from one branch and apply
the changes from the other branch.

An example where we have two branches:

1. Package 'A' has been added
2. Package 'B' has been removed and package 'C' is added.

To resolve the conflict when we merge these two branches:

- We choose the branch that has the most changes, and accept the `composer.json` and `composer.lock`
  files from that branch. In this case, we choose the composer files from branch 2.
- We reapply the changes from the other branch (branch 1). In this case we have to run
  ```composer require package/A``` again.

## 2. Validating your merged files

Before committing, make sure the resulting `composer.json` and `composer.lock` files are valid.
To do this, run the following commands:

```sh
composer validate
composer install [--dry-run]
```

## Important considerations

Keep in mind that whenever merge conflicts occur on the lock file, the information about the exact version
new packages were locked on for one of the branches gets lost. When package A in branch 1 is constrained
as `^1.2.0` and locked as `1.2.0`, it might get updated when branch 2 is used as baseline and a new
`composer require package/A:^1.2.0` is executed, as that will use the most recent version that the
constraint allows when possible. There might be a version 1.3.0 for that package available by now, which
will now be used instead.

Choosing the correct [version constraints](../articles/versions.md) and making sure the packages adhere
to [semantic versioning](https://semver.org/) when using
[next significant release operators](versions.md#next-significant-release-operators) should make sure
that merging branches does not break anything by accidentally updating a dependency.
