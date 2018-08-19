# Should I commit the dependencies in my vendor directory?

The general recommendation is **no**. The vendor directory (or wherever your
dependencies are installed) should be added to `.gitignore`/`svn:ignore`/etc.

The best practice is to then have all the developers use Composer to install
the dependencies. Similarly, the build server, CI, deployment tools etc should
be adapted to run Composer as part of their project bootstrapping.

While it can be tempting to commit it in some environment, it leads to a few
problems:

- Large VCS repository size and diffs when you update code.
- Duplication of the history of all your dependencies in your own VCS.
- Adding dependencies installed via git to a git repo will show them as
  submodules. This is problematic because they are not real submodules, and you
  will run into issues.

Committing the dependencies might be an option if you:

- have very high demands on reproducibility. Committing vendor makes you
  independent of upstream changes (e.g. renames, deletes, history overwrites)
  and makes you´re build process resilient against outages of upstream servers.
- require a single diff with all changes, including upstream changes.
  This could make failure analysis and traceability easier.
- want to reduce your build times
  (and other options like caching do not work well in your environment)

If you really feel like you must do this, you have a few options:

1. Limit yourself to installing tagged releases (no dev versions), so that you
   only get zipped installs, and avoid problems with the git "submodules".
2. Use --prefer-dist or set `preferred-install` to `dist` in your
   [config](../04-schema.md#config).
3. Remove the `.git` directory of every dependency after the installation, then
   you can add them to your git repo. You can do that with `rm -rf vendor/**/.git`
   in ZSH or `find vendor/ -type d -name ".git" -exec rm -rf {} \;` in Bash.
   but this means you will have to delete those dependencies from disk before
   running composer update.
4. Add a .gitignore rule (`/vendor/**/.git`) to ignore all the vendor `.git` folders.
   This approach does not require that you delete dependencies from disk prior to
   running a composer update.
