# Why are unbound version constraints a bad idea?

A version constraint without an upper bound such as `*`, `>=3.4` or
`dev-master` will allow updates to any future version of the dependency.
This includes major versions breaking backward compatibility.

Once a release of your package is tagged, you cannot tweak its dependencies
anymore in case a dependency breaks BC - you have to do a new release, but the
previous one stays broken.

The only good alternative is to define an upper bound on your constraints,
which you can increase in a new release after testing that your package is
compatible with the new major version of your dependency.

For example instead of using `>=3.4` you should use `~3.4` which allows all
versions up to `3.999` but does not include `4.0` and above. The `^` operator
works very well with libraries following [semantic versioning](https://semver.org).

**Note:** As a package maintainer, you can help your users
by providing an [alias version](../articles/aliases.md) for your development
branch to allow it to match bound constraints.
