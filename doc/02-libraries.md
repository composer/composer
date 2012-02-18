# Libraries

This chapter will tell you how to make your library installable through composer.

## Every project is a package

As soon as you have a `composer.json` in a directory, that directory is a package. When you add a `require` to a project, you are making a package that depends on other packages. The only difference between your project and libraries is that your project is a package without a name.

In order to make that package installable you need to give it a name. You do this by adding a `name` to `composer.json`:

```json
{
    "name": "acme/hello-world",
    "require": {
        "monolog/monolog": "1.0.*"
    }
}
```

In this case the project name is `acme/hello-world`, where `acme` is the vendor name. Supplying a vendor name is mandatory.

**Note:** If you don't know what to use as a vendor name, your GitHub username is usually a good bet. The convention for word separation is to use dashes.

## Specifying the version

You need to specify the version some way. Depending on the type of repository you are using, it might be possible to omit it from `composer.json`, because the repository is able to infer the version from elsewhere.

If you do want to specify it explicitly, you can just add a `version` field:

```json
{
    "version": "1.0.0"
}
```

However if you are using git, svn or hg, you don't have to specify it. Composer will detect versions as follows:

### Tags

For every tag that looks like a version, a package version of that tag will be created. It should match 'X.Y.Z' or 'vX.Y.Z', with an optional suffix for RC, beta, alpha or patch.

Here are a few examples of valid tag names:

    1.0.0
    v1.0.0
    1.10.5-RC1
    v4.4.4beta2
    v2.0.0-alpha
    v2.0.4-p1

**Note:** If you specify an explicit version in `composer.json`, the tag name must match the specified version.

### Branches

For every branch, a package development version will be created. If the branch name looks like a version, the version will be `{branchname}-dev`. For example a branch `2.0` will get a version `2.0-dev`. If the branch does not look like a version, it will be `dev-{branchname}`. `master` results in a `dev-master` version.

Here are some examples of version branch names:

    1.0
    1.*
    1.1.x
    1.1.*
