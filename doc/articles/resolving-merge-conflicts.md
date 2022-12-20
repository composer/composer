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
  files from that branch. In this case, we choose the Composer files from branch 2.
- We reapply the changes from the other branch (branch 1). In this case we have to run
  `composer require package/A` again.

## 2. Validating your merged files

Before committing, make sure the resulting `composer.json` and `composer.lock` files are valid.
To do this, run the following commands:

```shell
composer validate
composer install [--dry-run]
```

## Automating merge conflict resolving with git

Some improvement _could_ be made to git's conflict resolving by using a custom git merge driver.

An example of this can be found at [balbuf's composer git merge driver](https://github.com/balbuf/composer-git-merge-driver).

## Important considerations

Keep in mind that whenever merge conflicts occur on the lock file, the information, about the exact version
new packages were locked on for one of the branches, is lost. When package A in branch 1 is constrained
as `^1.2.0` and locked as `1.2.0`, it might get updated when branch 2 is used as baseline and a new
`composer require package/A:^1.2.0` is executed, as that will use the most recent version that the
constraint allows when possible. There might be a version 1.3.0 for that package available by now, which
will now be used instead.

Choosing the correct [version constraints](../articles/versions.md) and making sure the packages adhere
to [semantic versioning](https://semver.org/) when using
[next significant release operators](versions.md#next-significant-release-operators) should make sure
that merging branches does not break anything by accidentally updating a dependency.

# Recovering from incorrectly resolved merge conflicts

If the above steps aren't followed and text based merges have been done anyway,
your Composer project might be in a state where unexpected behaviour is observed
because the `composer.lock` file is not (fully) in sync with the `composer.json` file.

There are two things that can happen here:

1. There are packages in the `require` or `require-dev` section of the `composer.json` file that are not in the lock file and as a result never installed

> **Note:** Starting from Composer release 2.5, packages that are required but not locked will result in an error when running ```composer install```

2. There are packages in the `composer.lock` file that are not a direct or indirect dependency of any of the packages required. As a result, a package is installed, even though running `composer why vendor/package` says it is not required.

There are several ways to fix these issues;

## A. Start from scratch

The easiest but most impactful option is run a `composer update` to resolve to a correct state from scratch.

A drawback to this is that previously locked package versions are now updated, as the information about previous package versions has been lost. If all your dependencies follow [semantic versioning](https://semver.org/) and your [version constraints](../articles/versions.md) are using [next significant release operators](versions.md#next-significant-release-operators) this should not be an issue, otherwise you might inadvertently break your application.

## B. Reconstruct from the git history

An option that is probably not very feasible in a lot of situations but that deserves an honorable mention;

It might be possible to reconstruct the correct package state by going back into the git history and finding the most recent valid `composer.lock` file, and re-requiring the new dependencies from there.

## C. Resolve issues manually

There is an option to recover from a discrepancy between the `composer.json` and `composer.lock` file without having to dig through the git history or starting from scratch. For that, we need to solve issue 1 and 2 separately.

### 1. Detecting and fixing missing required packages

To detect any package that is required but not installed, you can simply run:

```shell
composer validate
```

If there are packages that are required but not installed, you should get output similar to this:

```shell
./composer.json is valid but your composer.lock has some errors
# Lock file errors
- Required package "vendor/package-name" is not present in the lock file.
This usually happens when composer files are incorrectly merged or the composer.json file is manually edited.
Read more about correctly resolving merge conflicts https://getcomposer.org/doc/articles/resolving-merge-conflicts.md
and prefer using the "require" command over editing the composer.json file directly https://getcomposer.org/doc/03-cli.md#require
```

To recover from this, simply run `composer update vendor/package-name` for each package listed here. After doing this for each package listed here, running `composer validate` again should result in no lock file errors:

```shell
./composer.json is valid
```

### 2. Detecting and fixing superfluous packages

To detect and fix packages that are locked but not a direct/indirect dependency, you can run the following command:

```shell
composer remove --unused
```

If there are no packages locked that are not a dependency, the command will have the following output:

```shell
No unused packages to remove
```

If there are packages to be cleaned up, the output will be as follows:

```shell
vendor/package-name is not required in your composer.json and has not been removed
./composer.json has been updated
Running composer update vendor/package-name
Loading composer repositories with package information
Updating dependencies
Lock file operations: 0 installs, 0 updates, 1 removal
  - Removing vendor/package-name (1.0)
Writing lock file
Installing dependencies from lock file (including require-dev)
Nothing to install, update or remove
```
