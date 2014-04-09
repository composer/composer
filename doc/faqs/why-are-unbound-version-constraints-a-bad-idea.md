# Why are unbound version constraints a bad idea?

A version constraint without an upper bound will allow any future version of
the dependency, even newer major version breaking backward compatibility
(which is the only reason to bump the major version when following semver).

Once a release of your package is tagged, you cannot tweak its dependencies
anymore in case a dependency breaks BC (you have to do a new release but the
previous one stays broken).

These leaves you with 3 alternatives to avoid having broken releases:

- defining an upper bound on your constraint (which you will increase in a
  new release after testing that your package is compatible with the new
  version)

- knowing all future changes of your dependency to guarantee the compatibility
  of the current code. Forget this alternative unless you are Chuck Norris :)

- never release your package, but this means that all users will have to
  whitelist the dev versions to install it (and complain about it)

The recommended way is of course to define an upper bound on your constraint,
so Composer will show you a warning for unbound constraints when validating
your `composer.json` file.

As a package maintainer, you can make the life of your users easier by
providing an [alias version](../articles/aliases.md) for your development
branch to allow it to match bound constraints.
