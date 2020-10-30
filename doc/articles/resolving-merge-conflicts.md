<!--
    tagline: On gracefully resolving conflicts while merging
-->

# Resolving merge conflicts

When working as a team on the same Composer project, you'll eventually run into a scenario where multiple people added, updated or removed something in the `composer.json` and `composer.lock` files in multiple branches. When those branches are eventually merged together, you'll get merge conflicts. Resolving these merge conflicts is not as straight forward as on other files, especially not regarding the `composer.lock` file.

## A. Reapplying command line changes

The safest method to merge Composer files is to accept the version from one branch and apply the changes from the other branch.

An example where we have two branches:
1. Package 'A' has been added
2. Package 'B' has been removed and package 'C' is added.

To resolve the conflict when we merge these two branches:
- We choose the branch that has the most changes, and accept the `composer.json` and `composer.lock` files from that branch. In this case, we choose the composer files from branch 2.
- We reapply the changes from the other branch (branch 1). In this case we have to run ```composer require package/A``` again.

## B. Manually merging the `composer.json` and updating the `composer.lock` file

Conflicts in the `composer.json` file can also be manually resolved like any other non-binary or non-lock file. An important thing to keep in mind is that the resulting file is valid json. Be especially aware of trailing commas, those are not allowed in JSON.

After manually merging the `composer.json`, we also need to make sure the lock file is valid again. Manually merging the lock file is not recommended. Instead, use the `composer.lock` from one of the branches and apply the updates from the `composer.json` to the lock file using the following command:

```sh
composer update --lock
```

This method is easiest when at least one of the braches doesn't have any changes to the `require` and `require-dev` section.

## Validating your merged files

Before comitting, make sure the resulting `composer.json` and `composer.lock` files are valid. If you followed method A, you should never encounter an anvalid file, but it is good to still make sure. If you did merge the composer.json manually using method B you should always run this command as that method is more error prone.

```sh
composer validate
```

## Important considerations

Keep in mind that whenever merge conflicts occur on the lock file, the information about the exact version new packages were locked on for one of the branches gets lost. When package A in branch 1 is constrained as `^1.2.0` and locked as `1.2.0`, it might get updated when branch 2 is used as baseline and a new `composer require package/A:^1.2.0` is executed, as that will use the most recent version that the constraint allows when possible. There might be a version 1.3.0 for that package available by now, which will now be used instead.

Choosing the correct [version constraints](../articles/versions.md) and making sure the packages adhere to [semantic versioning](https://semver.org/) when using [netx significant release operators](https://getcomposer.org/doc/articles/versions.md#next-significant-release-operators) should make sure that merging branches doesn't break anything by accidentally updating a dependency.
