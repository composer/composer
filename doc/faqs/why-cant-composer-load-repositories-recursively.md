# Why can't Composer load repositories recursively?

You may run into problems when using custom repositories because Composer does
not load the repositories of your requirements, so you have to redefine those
repositories in all your `composer.json` files.

Before going into details as to why this is like that, you have to understand
that the main use of custom VCS & package repositories is to temporarily try
some things, or use a fork of a project until your pull request is merged, etc.
You should not use them to keep track of private packages. For that you should
rather look into [Private Packagist](https://packagist.com) which lets you
configure all your private packages in one place, and avoids the slow-downs
associated with inline VCS repositories.

There are three ways the dependency solver could work with custom repositories:

-   Fetch the repositories of root package, get all the packages from the defined
    repositories, then resolve requirements. This is the current state and it works well
    except for the limitation of not loading repositories recursively.

-   Fetch the repositories of root package, while initializing packages from the
    defined repos, initialize recursively all repos found in those packages, and
    their package's packages, etc, then resolve requirements. It could work, but it
    slows down the initialization a lot since VCS repos can each take a few seconds,
    and it could end up in a completely broken state since many versions of a package
    could define the same packages inside a package repository, but with different
    dist/source. There are many ways this could go wrong.

-   Fetch the repositories of root package, then fetch the repositories of the
    first level dependencies, then fetch the repositories of their dependencies, etc,
    then resolve requirements. This sounds more efficient, but it suffers from the
    same problems as the second solution, because loading the repositories of the
    dependencies is not as easy as it sounds. You need to load all the repos of all
    the potential matches for a requirement, which again might have conflicting
    package definitions.
