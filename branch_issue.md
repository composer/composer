# Problem
`replace` and `provide` were rendered basically inert by #2690 [because one particular
repository composer can use is open](https://github.com/composer/composer/issues/2690#issuecomment-35715921)
to unvetted submissions, and this could allow any malicious publisher to make Composer
select their replacement of a popular dependency.

However, if the repository a `replace` or `provide` originates from is not open, 
but rather is maintained entirely by an organization the composer user trusts, then it is still safe to
honor those `provide`/`replace` relationships, and when working with such repositories
it would be incredibly useful for these to work like they were originally intended. So,
this PR replaces hardcoded logic in the dependency resolver that was tailored to packagist
with a decision based on a repository-specific property.  

# Motivation
I'm working on a project that aims to utilize Composer more like the package manager of a
Linux distribution than as a developer tool. That is, it would primarily install things
not from packagist, but from a more stable repository of selected packages that are maintained
for security and major bugfixes only from upstream counterparts. (Drupal 8 is the impetus,
you can read more about it [here](https://iglue.io/).) 

Within this model, I certainly don't want to completely exclude packagist (or packages.drupal.org). As with a Linux system, you may need additional software sources, but you still 
mostly enjoy the stability of your base distribution if you only install a package here and there from the wider Internet.
So I need Composer to add packagist to the `Pool`, but to prefer the versions of dependencies in
iglue when they can be used with whatever else a user may require.

Composer already behaves this way (and even has test coverage) if I name
my vendors and packages to match those in packagist. Awesome! But I wonder if we can improve on
this solution, because
 1. Using packagist vendor/package names makes my packages indistinguishable from upstream packages in the
    [FriendsOfPHP security advisory database](https://github.com/FriendsOfPHP/security-advisories),
    and this would prevent writing a generic security status checker for Drupal 8 that
    works whether you choose to source packages from iglue or direct from upstream authors.
 2. En-masse plagarism of vendor and package names in another repository just seems like not the best idea.

So instead of using the same vendor names, I've been experimenting with suffixing "@iglue" to vendors,
e.g. [symfony@iglue/routing](https://iglue.io/1-unstable/packages/symfony@iglue/routing) and using `provide`
/ `replace` / `conflict` to allow them to satisfy dependencies.

# Considerations
 * The essential part of this change is just locating what provides requirements without requiring exact name matching
 * In my estimation, vcs repositories that provide only one package and are added by the user are
   trusted by the user, so it'd be fine to class them as fully trusted and . However, it might be disorienting & frustrating to find out your composer.json
   works differently when you push it to packagist than it did when you developed it in git.

# Benefits
