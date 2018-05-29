# Default Solver Policy

A solver policy defines behaviour variables of the dependency solver. It decides
which versions are considered newer than others, which packages should be
preferred over others and whether operations like downgrades or uninstall are
allowed.

## Selection of preferred Packages

The following describe package pool situations with user requests and the
resulting order in which the solver will try to install them.

The rules are to be applied in the order of these descriptions.

### Repository priorities

Packages Repo1.Av1, Repo2.Av1

* priority(Repo1) >= priority(Repo2) => (Repo1.Av1, Repo2.Av1)
* priority(Repo1) <  priority(Repo2) => (Repo2.Av1, Repo1.Av1)

### Package versions

Packages: Av1, Av2, Av3

* Installed: Av2

Request: install A

* (Av3)

### Virtual Packages (provides)

Packages Av1, Bv1

* Av1 provides Xv1
* Bv1 provides Xv1

Request: install X

* priority(Av1.repo) >= priority(Bv1.repo) => (Av1, Bv1)
* priority(Av1.repo) <  priority(Bv1.repo) => (Bv1, Av1)

### Package replacements

Packages: Av1, Bv2

* Bv2 replaces Av1

Request: install A

* priority(Av1.repo) >= priority(Bv2.repo) => (Av1, Bv2)
* priority(Av1.repo) <  priority(Bv2.repo) => (Bv2, Av1)

Bv2 version is ignored, only the replacement version for A matters.
