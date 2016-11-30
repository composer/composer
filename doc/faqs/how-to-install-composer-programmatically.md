# How to install Composer programmatically?

As noted on the download page, the installer script contains a
signature which changes when the installer code changes and as such
it should not be relied upon long term.

An alternative is to use this script which only works with unix utils:

```bash
#!/bin/sh

EXPECTED_SIGNATURE=$(wget -q -O - https://composer.github.io/installer.sig)
php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
ACTUAL_SIGNATURE=$(php -r "echo hash_file('SHA384', 'composer-setup.php');")

if [ "$EXPECTED_SIGNATURE" != "$ACTUAL_SIGNATURE" ]
then
    >&2 echo 'ERROR: Invalid installer signature'
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

Alternatively if you want to rely on an exact copy of the installer you can fetch
a specific version from github's history. The commit hash should be enough to
give it uniqueness and authenticity as long as you can trust the GitHub servers.
For example:

```bash
wget https://raw.githubusercontent.com/composer/getcomposer.org/1b137f8bf6db3e79a38a5bc45324414a6b1f9df2/web/installer -O - -q | php -- --quiet
```

You may replace the commit hash by whatever the last commit hash is on
https://github.com/composer/getcomposer.org/commits/master
