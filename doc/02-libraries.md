# Libraries

This chapter will tell you how to make your library installable through
Composer.

## Every project is a package

As soon as you have a `composer.json` in a directory, that directory is a
package. When you add a [`require`](04-schema.md#require) to a project, you are
making a package that depends on other packages. The only difference between
your project and a library is that your project is a package without a name.

In order to make that package installable you need to give it a name. You do
this by adding the [`name`](04-schema.md#name) property in `composer.json`:

```json
{
    "name": "acme/hello-world",
    "require": {
        "monolog/monolog": "1.0.*"
    }
}
```

In this case the project name is `acme/hello-world`, where `acme` is the vendor
name. Supplying a vendor name is mandatory.

> **Note:** If you don't know what to use as a vendor name, your GitHub
> username is usually a good bet. Package names must be lowercase, and the
> convention is to use dashes for word separation.

## Library Versioning

In the vast majority of cases, you will be maintaining your library using some
sort of version control system like git, svn, hg or fossil. In these cases,
Composer infers versions from your VCS, and you **should not** specify a version
in your `composer.json` file. (See the [Versions article](articles/versions.md)
to learn about how Composer uses VCS branches and tags to resolve version
constraints.)

If you are maintaining packages by hand (i.e., without a VCS), you'll need to
specify the version explicitly by adding a `version` value in your `composer.json`
file:

```json
{
    "version": "1.0.0"
}
```

> **Note:** When you add a hardcoded version to a VCS, the version will conflict
> with tag names. Composer will not be able to determine the version number.

### VCS Versioning

Composer uses your VCS's branch and tag features to resolve the version
constraints you specify in your [`require`](04-schema.md#require) field to specific sets of files.
When determining valid available versions, Composer looks at all of your tags
and branches and translates their names into an internal list of options that
it then matches against the version constraint you provided.

For more on how Composer treats tags and branches and how it resolves package
version constraints, read the [versions](articles/versions.md) article.

## Lock file

For your library it is **recommended to commit the `composer.lock` file**. This
helps your team to always test against the same dependency versions, and it
can help prevent supply chain attacks from propagating, as newly published
(possibly malicious) dependency versions are not pulled into your CI or
development environment until you explicitly update.

This protection only covers working on the library itself. The lock file has no
effect on projects that depend on your library, they install whatever versions
your constraints allow. You can therefore exclude it from dist archives using
`export-ignore`, see [Light-weight distribution packages](#light-weight-distribution-packages).

As your users will not get the locked versions, keep the lock file up to date
and also run CI jobs with `composer update` (and `composer update --prefer-lowest`)
to catch breakage from new releases or too low lower bounds. As these jobs install
unlocked versions, consider configuring a [cooldown](06-config.md#cooldown), do not
give them access to secrets or write permissions (e.g. to your repository), and do
not run `composer update` in any build or release steps.

## Publishing to a VCS

Once you have a VCS repository (version control system, e.g. git) containing a
`composer.json` file, your library is already composer-installable. In this
example we will publish the `acme/hello-world` library on GitHub under
`github.com/username/hello-world`.

Now, to test installing the `acme/hello-world` package, we create a new
project locally. We will call it `acme/blog`. This blog will depend on
`acme/hello-world`, which in turn depends on `monolog/monolog`. We can
accomplish this by creating a new `blog` directory somewhere, containing a
`composer.json`:

```json
{
    "name": "acme/blog",
    "require": {
        "acme/hello-world": "dev-master"
    }
}
```

The name is not needed in this case, since we don't want to publish the blog
as a library. It is added here to clarify which `composer.json` is being
described.

Now we need to tell the blog app where to find the `hello-world` dependency.
We do this by adding a package repository specification to the blog's
`composer.json`:

```json
{
    "name": "acme/blog",
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/username/hello-world"
        }
    ],
    "require": {
        "acme/hello-world": "dev-master"
    }
}
```

For more details on how package repositories work and what other types are
available, see [Repositories](05-repositories.md).

That's all. You can now install the dependencies by running Composer's
[`install`](03-cli.md#install--i) command!

**Recap:** Any git/svn/hg/fossil repository containing a `composer.json` can be
added to your project by specifying the package repository and declaring the
dependency in the [`require`](04-schema.md#require) field.

## Publishing to packagist

Alright, so now you can publish packages. But specifying the VCS repository
every time is cumbersome. You don't want to force all your users to do that.

The other thing that you may have noticed is that we did not specify a package
repository for `monolog/monolog`. How did that work? The answer is Packagist.

[Packagist](https://packagist.org/) is the main package repository for
Composer, and it is enabled by default. Anything that is published on
Packagist is available automatically through Composer. Since
[Monolog is on Packagist](https://packagist.org/packages/monolog/monolog), we
can depend on it without having to specify any additional repositories.

If we wanted to share `hello-world` with the world, we would publish it on
Packagist as well.

You visit [Packagist](https://packagist.org) and hit the "Submit"
button. This will prompt you to sign up if you haven't already, and then
allows you to submit the URL to your VCS repository, at which point Packagist
will start crawling it. Once it is done, your package will be available to
anyone!

## Light-weight distribution packages

Some useless information like the `.github` directory, or large examples, test
data, etc. should typically not be included in distributed packages.

The `.gitattributes` file is a git specific file like `.gitignore` also living
at the root directory of your library. It overrides local and global
configuration (`.git/config` and `~/.gitconfig` respectively) when present and
tracked by git.

Use `.gitattributes` to prevent unwanted files from bloating the zip
distribution packages.

```text
// .gitattributes
/demo export-ignore
phpunit.xml.dist export-ignore
/.github/ export-ignore
/composer.lock export-ignore
```

Test it by inspecting the zip file generated manually:

```shell
git archive branchName --format zip -o file.zip
```

> **Note:** Files would be still tracked by git just not included in the
> zip distribution. This only works for packages installed from
> dist (i.e. tagged releases) coming from GitHub, GitLab or Bitbucket.

## Security advisories

When a vulnerability exists in a library you manage, you can fix the vulnerability
and publish a new release. Maybe some users will be notified that you published a
new release because they subscribe to your repository, but most users won't know
that they'd have to update to fix the underlying vulnerability.

### How users get notified about advisories

When users run `composer audit` or automated security advisory checks in their CI,
security advisories of all their dependencies are retrieved via the
[packagist.org API](https://packagist.org/apidoc#list-security-advisories).

Packagist aggregates security advisories from multiple sources:

- [GitHub Security Advisories](#creating-and-publishing-an-advisory-on-github)
- [FriendsOfPHP Security Advisories](#registering-an-advisory-with-friendsofphp)

It doesn't retrieve published advisories from your repository itself.

### Creating and publishing an advisory on GitHub

If you are using GitHub to host the code for your library, you can create a
security advisory in the "Security and quality" tab.

This will result in a unique url and GHSA-ID that can be used to reference
this advisory. Please note it does NOT mean that users of your library will
immediately get notified.

### How GitHub Security Advisories are picked up

Once you have [created and published](#creating-and-publishing-an-advisory-on-github)
a security advisory on GitHub, it MIGHT automatically show up in the
[GitHub advisory database](https://github.com/github/advisory-database). According
to documentation, "GitHub will review each published security advisory", and mentions
[this might take up to 72 hours](https://docs.github.com/en/code-security/concepts/vulnerability-reporting-and-management/repository-security-advisories#dependabot-alerts-for-published-security-advisories),
but in practice this may take significantly longer given that these reports are
reviewed manually.

The `github/advisory-database` repository, where these are managed in, in turn also
includes advisories from the `friendsofphp/security-advisories` repository. As such,
it is recommended to add your advisory to that repository instead to make sure they
are visible to users as soon as possible.

### Registering an advisory with FriendsOfPHP

To add an advisory to the `friendsofphp/security-advisories`
[repository](https://github.com/FriendsOfPHP/security-advisories), simply
open a Pull Request using the guide in their README. Once this Pull Request is
merged, the repository update will
[automatically trigger a new security advisory sync](https://github.com/composer/packagist/blob/858c6b430cffa3f070cc2c868afc572caefb5244/src/Service/UpdaterWorker.php#L412)
on packagist.org and the advisory will be available immediately there.

This will also eventually result in the advisory ending up in the
`github/advisory-database` repository if it's not in there already.

### Verifying advisory discoverability on packagist.org

You can see if a security advisory is already known by Packagist by searching for
the PKSA, CVE or GHSA IDs in the
[Security Advisories search](https://packagist.org/security-advisories/).

### Why is GitHub's Dependabot not notifying users of my libraries' advisory?

Dependabot currently only uses the `github/advisory-database` repository as a
source of advisories. If your published advisory is not in there, it won't trigger
dependabot. To resolve this, either publish your advisory to
[`friendsofphp/security-advisories`](#registering-an-advisory-with-friendsofphp)
or, if you've done that already wait, for it to be picked up by
[`github/advisory-database`](#how-github-security-advisories-are-picked-up)

&larr; [Basic usage](01-basic-usage.md) |  [Command-line interface](03-cli.md) &rarr;
