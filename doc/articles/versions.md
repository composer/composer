<!--
    tagline: Versions explained.
-->

# Versions

## Composer Versions vs VCS Versions

Because Composer is heavily geared toward utilizing version control systems
like git, the term "version" can be a little ambiguous. In the sense of a
version control system, a "version" is a specific set of files that contain
specific data. In git terminology, this is a "ref", or a specific commit,
which may be represented by a branch HEAD or a tag. When you check out that
version in your VCS -- for example, tag `v1.1` or commit `e35fa0d` --, you're
asking for a single, known set of files, and you always get the same files back.

In Composer, what's usually referred to casually as a version -- that is,
the string that follows the package name in a require line (e.g., `~1.1` or
`1.2.*`) -- is actually more specifically a version constraint. Composer
uses version constraints to figure out which refs in a VCS it should be
checking out.

### Tags vs Branches

Normally, Composer deals with tags (as opposed to branches -- if you don't
know what this means, read up on
[version control systems](https://en.wikipedia.org/wiki/Version_control#Common_vocabulary)).
When referencing a tag, it may reference a specific tag (e.g., `1.1`) or it
may reference a valid range of tags (e.g., `>=1.1 <2.0`). Furthermore, you
can add "stability specifiers" to let Composer know that you are or aren't
interested in certain tags, like alpha releases, beta releases, or release
candidates, even if they're technically within the numeric range specified
by the version constraint (these releases are usually considered "unstable",
hence the term "stability specifier"). 

If you want Composer to check out a branch instead of a tag, you use the
special syntax described [here](02-libraries.md#branches). In short, if
you're checking out a branch, it's assumed that you want to *work* on the
branch and Composer simply clones the repo into the correct place in your
`vendor` directory. (For tags, it just copies the right files without actually
cloning the repo.) This can be very convenient for libraries under development,
as you can make changes to the dependency files your project is actually using
and still commit them to their respective repos as patches or other updates.

Let's look at an example. Suppose you've published a library whose git repo
looks like this:

```sh
$ git branch
$ 
$ v1
$ v2
$ my-feature
$ nother-feature
$
$ git tag
$ 
$ v1.0
$ v1.0.1
$ v1.0.2
$ v1.1-BETA
$ v1.1-RC1
$ v1.1-RC2
$ v1.1
$ v1.1.1
$ v2.0-BETA
$ v2.0-RC1
$ v2.0
$ v2.0.1
$ v2.0.2
```

Now assume you've got a project that depends on this library and you've been
running `composer update` in that project since the `v1.0` release. If you
specified `~1.0` in Composer (the tilde modifier, among others, is detailed
below), and you don't add a [`minimum-stability`](04-schema.md#minimum-stability)
key elsewhere in the file, then Composer will default to "stable" as a minimum
stability setting and you will receive only the `v1.0`, `v1.0.1`, `v1.0.2`,
`v1.1` and `v1.1.1` tags as the tags are created in your VCS. If you set the
`minimum-stability` key to `RC`, you would receive the aforementioned tags as
they're released, plus the `v1.1-RC1` and `v1.1-RC2` tags, but not `v1.1-BETA`.
(You can see the available stability constraints in order on the
[schema page](04-schema.md#minimum-stability).

The final important detail here is how branches are handled. In git, a branch
simply represents a series of commits, with the current "HEAD" of the branch
pointing at the most recent in the chain. A tag is a specific commit, independent
of branch. By default composer checks out the tag that best matches the version
constraint you've specified. However, if you specify the version constraint as
"v1-dev" (or sometimes "dev-my-branch" -- see the [libraries page](02-libraries.md#branches)
for syntax details), then Composer will clone the repo into your `vendor`
  directory, checking out the `v1` branch.

## Basic Version Constraints

Now that you have an idea of how Composer sees versions, let's talk about how
to specify version constraints for your project dependencies.

### Exact

You can specify the exact version of a package. This will tell Composer to
install this version and this version only. If other dependencies require
a different version, the solver will ultimately fail and abort any install
or update procedures.

Example: `1.0.2`

### Range

By using comparison operators you can specify ranges of valid versions. Valid
operators are `>`, `>=`, `<`, `<=`, `!=`.

You can define multiple ranges. Ranges separated by a space (<code>&nbsp;</code>)
or comma (`,`) will be treated as a **logical AND**. A double pipe (`||`)
will be treated as a **logical OR**. AND has higher precedence than OR.

> **Note:** Be careful when using unbounded ranges as you might end up
> unexpectedly installing versions that break backwards compatibility.
> Consider using the [caret](#caret) operator instead for safety.

Examples:

* `>=1.0`
* `>=1.0 <2.0`
* `>=1.0 <1.1 || >=1.2`

### Range (Hyphen)

Inclusive set of versions. Partial versions on the right include are completed
with a wildcard. For example `1.0 - 2.0` is equivalent to `>=1.0.0 <2.1` as the
`2.0` becomes `2.0.*`. On the other hand `1.0.0 - 2.1.0` is equivalent to
`>=1.0.0 <=2.1.0`.

Example: `1.0 - 2.0`

### Wildcard

You can specify a pattern with a `*` wildcard. `1.0.*` is the equivalent of
`>=1.0 <1.1`.

Example: `1.0.*`

## Next Significant Release Operators

### Tilde

The `~` operator is best explained by example: `~1.2` is equivalent to
`>=1.2 <2.0.0`, while `~1.2.3` is equivalent to `>=1.2.3 <1.3.0`. As you can see
it is mostly useful for projects respecting [semantic
versioning](http://semver.org/). A common usage would be to mark the minimum
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

### Caret

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

## Test version constraints

You can test version constraints using [semver.mwl.be](https://semver.mwl.be).
Fill in a package name and it will autofill the default version constraint
which Composer would add to your `composer.json` file. You can adjust the
version constraint and the tool will highlight all releases that match.
