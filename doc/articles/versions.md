<!--
    tagline: Versions explained.
-->

# Versions and constraints

## Composer Versions vs VCS Versions

Because Composer is heavily geared toward utilizing version control systems
like git, the term "version" can be a little ambiguous. In the sense of a
version control system, a "version" is a specific set of files that contain
specific data. In git terminology, this is a "ref", or a specific commit,
which may be represented by a branch HEAD or a tag. When you check out that
version in your VCS -- for example, tag `v1.1` or commit `e35fa0d` --, you're
asking for a single, known set of files, and you always get the same files back.

In Composer, what's often referred to casually as a version -- that is,
the string that follows the package name in a require line (e.g., `~1.1` or
`1.2.*`) -- is actually more specifically a version constraint. Composer
uses version constraints to figure out which refs in a VCS it should be
checking out (or simply to verify that a given library is acceptable in
the case of a statically-maintained library with a `version` specification
in `composer.json`).

## VCS Tags and Branches

*For the following discussion, let's assume the following sample library
repository:*

```sh
~/my-library$ git branch
v1
v2
my-feature
another-feature

~/my-library$ git tag
v1.0
v1.0.1
v1.0.2
v1.1-BETA
v1.1-RC1
v1.1-RC2
v1.1
v1.1.1
v2.0-BETA
v2.0-RC1
v2.0
v2.0.1
v2.0.2
```

### Tags

Normally, Composer deals with tags (as opposed to branches -- if you don't
know what this means, read up on
[version control systems](https://en.wikipedia.org/wiki/Version_control#Common_terminology)).
When you write a version constraint, it may reference a specific tag (e.g.,
`1.1`) or it may reference a valid range of tags (e.g., `>=1.1 <2.0`, or
`~4.0`). To resolve these constraints, Composer first asks the VCS to list
all available tags, then creates an internal list of available versions based
on these tags. In the above example, composer's internal list includes versions
`1.0`, `1.0.1`, `1.0.2`, the beta release of `1.1`, the first and second
release candidates of `1.1`, the final release version `1.1`, etc.... (Note
that Composer automatically removes the 'v' prefix in the actual tagname to
get a valid final version number.)

When Composer has a complete list of available versions from your VCS, it then
finds the highest version that matches all version constraints in your project
(it's possible that other packages require more specific versions of the
library than you do, so the version it chooses may not always be the highest
available version) and it downloads a zip archive of that tag to unpack in the
correct location in your `vendor` directory.

### Branches

If you want Composer to check out a branch instead of a tag, you need to point it to the branch using the special `dev-*` prefix (or sometimes suffix; see below). If you're checking out a branch, it's assumed that you want to *work* on the branch and Composer actually clones the repo into the correct place in your `vendor` directory. For tags, it copies the right files without actually cloning the repo. (You can modify this behavior with --prefer-source and --prefer-dist, see [install options](../03-cli.md#install).)

In the above example, if you wanted to check out the `my-feature` branch, you would specify `dev-my-feature` as the version constraint in your `require` clause. This would result in Composer cloning the `my-library` repository into my `vendor` directory and checking out the `my-feature` branch.

When branch names look like versions, we have to clarify for composer that we're trying to check out a branch and not a tag. In the above example, we have two version branches: `v1` and `v2`. To get Composer to check out one of these branches, you must specify a version constraint that looks like this: `v1.x-dev`. The `.x` is an arbitrary string that Composer requires to tell it that we're talking about the `v1` branch and not a `v1` tag (alternatively, you can name the branch `v1.x` instead of `v1`). In the case of a branch with a version-like name (`v1`, in this case), you append `-dev` as a suffix, rather than using `dev-` as a prefix.

### Minimum Stability

There's one more thing that will affect which files are checked out of a library's VCS and added to your project: Composer allows you to specify stability constraints to limit which tags are considered valid. In the above example, note that the library released a beta and two release candidates for version `1.1` before the final official release. To receive these versions when running `composer install` or `composer update`, we have to explicitly tell Composer that we are ok with release candidates and beta releases (and alpha releases, if we want those). This can be done using either a project-wide `minimum-stability` value in `composer.json` or using "stability flags" in version constraints. Read more on the [schema page](../04-schema.md#minimum-stability).

## Writing Version Constraints

Now that you have an idea of how Composer sees versions, let's talk about how
to specify version constraints for your project dependencies.

### Exact Version Constraint

You can specify the exact version of a package. This will tell Composer to
install this version and this version only. If other dependencies require
a different version, the solver will ultimately fail and abort any install
or update procedures.

Example: `1.0.2`

### Version Range

By using comparison operators you can specify ranges of valid versions. Valid
operators are `>`, `>=`, `<`, `<=`, `!=`.

You can define multiple ranges. Ranges separated by a space (<code>&nbsp;</code>)
or comma (`,`) will be treated as a **logical AND**. A double pipe (`||`)
will be treated as a **logical OR**. AND has higher precedence than OR.

> **Note:** Be careful when using unbounded ranges as you might end up
> unexpectedly installing versions that break backwards compatibility.
> Consider using the [caret](#caret-version-range-) operator instead for safety.

Examples:

* `>=1.0`
* `>=1.0 <2.0`
* `>=1.0 <1.1 || >=1.2`

### Hyphenated Version Range ( - )

Inclusive set of versions. Partial versions on the right include are completed
with a wildcard. For example `1.0 - 2.0` is equivalent to `>=1.0.0 <2.1` as the
`2.0` becomes `2.0.*`. On the other hand `1.0.0 - 2.1.0` is equivalent to
`>=1.0.0 <=2.1.0`.

Example: `1.0 - 2.0`

### Wildcard Version Range (.*)

You can specify a pattern with a `*` wildcard. `1.0.*` is the equivalent of
`>=1.0 <1.1`.

Example: `1.0.*`

## Next Significant Release Operators

### Tilde Version Range (~)

The `~` operator is best explained by example: `~1.2` is equivalent to
`>=1.2 <2.0.0`, while `~1.2.3` is equivalent to `>=1.2.3 <1.3.0`. As you can see
it is mostly useful for projects respecting [semantic
versioning](https://semver.org/). A common usage would be to mark the minimum
minor version you depend on, like `~1.2` (which allows anything up to, but not
including, 2.0). Since in theory there should be no backwards compatibility
breaks until 2.0, that works well. Another way of looking at it is that using
`~` specifies a minimum version, but allows the last digit specified to go up.

Example: `~1.2`

> **Note:** Although `2.0-beta.1` is strictly before `2.0`, a version constraint
> like `~1.2` would not install it. As said above `~1.2` only means the `.2`
> can change but the `1.` part is fixed.

> **Note:** The `~` operator has an exception on its behavior for the major
> release number. This means for example that `~1` is the same as `~1.0` as
> it will not allow the major number to increase trying to keep backwards
> compatibility.

### Caret Version Range (^)

The `^` operator behaves very similarly but it sticks closer to semantic
versioning, and will always allow non-breaking updates. For example `^1.2.3`
is equivalent to `>=1.2.3 <2.0.0` as none of the releases until 2.0 should
break backwards compatibility. For pre-1.0 versions it also acts with safety
in mind and treats `^0.3` as `>=0.3.0 <0.4.0`.

This is the recommended operator for maximum interoperability when writing
library code.

Example: `^1.2.3`

## Stability Constraints

If you are using a constraint that does not explicitly define a stability,
Composer will default internally to `-dev` or `-stable`, depending on the
operator(s) used. This happens transparently.

If you wish to explicitly consider only the stable release in the comparison,
add the suffix `-stable`.

Examples:

 Constraint         | Internally
------------------- | ------------------------
 `1.2.3`            | `=1.2.3.0-stable`
 `>1.2`             | `>1.2.0.0-stable`
 `>=1.2`            | `>=1.2.0.0-dev`
 `>=1.2-stable`     | `>=1.2.0.0-stable`
 `<1.3`             | `<1.3.0.0-dev`
 `<=1.3`            | `<=1.3.0.0-stable`
 `1 - 2`            | `>=1.0.0.0-dev <3.0.0.0-dev`
 `~1.3`             | `>=1.3.0.0-dev <2.0.0.0-dev`
 `1.4.*`            | `>=1.4.0.0-dev <1.5.0.0-dev`

To allow various stabilities without enforcing them at the constraint level
however, you may use [stability-flags](../04-schema.md#package-links) like
`@<stability>` (e.g. `@dev`) to let composer know that a given package
can be installed in a different stability than your default minimum-stability
setting. All available stability flags are listed on the minimum-stability
section of the [schema page](../04-schema.md#minimum-stability).

## Summary
```json
"require": {
    "vendor/package": "1.3.2", // exactly 1.3.2

    // >, <, >=, <= | specify upper / lower bounds
    "vendor/package": ">=1.3.2", // anything above or equal to 1.3.2
    "vendor/package": "<1.3.2", // anything below 1.3.2

    // * | wildcard
    "vendor/package": "1.3.*", // >=1.3.0 <1.4.0

    // ~ | allows last digit specified to go up
    "vendor/package": "~1.3.2", // >=1.3.2 <1.4.0
    "vendor/package": "~1.3", // >=1.3.0 <2.0.0

    // ^ | doesn't allow breaking changes (major version fixed - following semver)
    "vendor/package": "^1.3.2", // >=1.3.2 <2.0.0
    "vendor/package": "^0.3.2", // >=0.3.2 <0.4.0 // except if major version is 0
}
```

## Testing Version Constraints

You can test version constraints using [semver.mwl.be](https://semver.mwl.be).
Fill in a package name and it will autofill the default version constraint
which Composer would add to your `composer.json` file. You can adjust the
version constraint and the tool will highlight all releases that match.
