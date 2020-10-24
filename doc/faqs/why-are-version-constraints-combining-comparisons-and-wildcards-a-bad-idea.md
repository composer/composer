# Why are version constraints combining comparisons and wildcards a bad idea?

This is a fairly common mistake people make, defining version constraints in
their package requires like `>=2.*` or `>=1.1.*`.

If you think about it and what it really means though, you will quickly
realize that it does not make much sense. If we decompose `>=2.*`, you
have two parts:

- `>=2` which says the package should be in version 2.0.0 or above.
- `2.*` which says the package should be between version 2.0.0 (inclusive)
  and 3.0.0 (exclusive).

As you see, both rules agree on the fact that the package must be >=2.0.0,
but it is not possible to determine if when you wrote that you were thinking
of a package in version 3.0.0 or not. Should it match because you asked for
`>=2` or should it not match because you asked for a `2.*`?

For this reason, Composer throws an error and says that this is invalid.
The way to fix it is to think about what you really mean, and use only
one of those rules.
