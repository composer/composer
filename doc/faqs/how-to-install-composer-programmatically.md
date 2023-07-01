![Approve](https://github.com/composer/composer/assets/122245952/a994ab5c-d0ab-4bd2-91ce-bb9776450dfb)
# How do I install Composer programmatically?

As noted on the download page, the installer script contains a
checksum which changes when the installer code changes and as such
it should not be relied upon in the long term.

An alternative is to use this script which only works with UNIX utilities:

```shell
#!/bin/sh

EXPECTED_CHECKSUM="$(php -r 'copy("https://composer.github.io/installer.sig", "php://stdout");')"
php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
ACTUAL_CHECKSUM="$(php -r "echo hash_file('sha384', 'composer-setup.php');")"

if [ "$EXPECTED_CHECKSUM" != "$ACTUAL_CHECKSUM" ]
then
    >&2 echo 'ERROR: Invalid installer checksum'
    rm composer-setup.php
    exit 1
fi

php composer-setup.php --quiet
RESULT=$?
rm composer-setup.php
exit $RESULT
```

The script will exit with 1 in case of failure, or 0 on success, and is quiet
if no error occurs.

Alternatively, if you want to rely on an exact copy of the installer, you can fetch
a specific version from GitHub's history. The commit hash should be enough to
give it uniqueness and authenticity as long as you can trust the GitHub servers.
For example:

```shell
wget https://raw.githubusercontent.com/composer/getcomposer.org/76a7060ccb93902cd7576b67264ad91c8a2700e2/web/installer -O - -q | php -- --quiet
```

You may replace the commit hash by whatever the last commit hash is on
https://github.com/composer/getcomposer.org/commits/main
