# How to install Composer programmatically?

As noted on the download page, the installer script contains a
signature which changes when the installer code changes and as such
it should not be relied upon long term.

An alternative is to use this script which only works with unix utils:

```bash
#!/bin/sh

EXPECTED_SIGNATURE=$(wget https://composer.github.io/installer.sig -O - -q)
php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
ACTUAL_SIGNATURE=$(php -r "echo hash_file('SHA384', 'composer-setup.php');")

if [ "$EXPECTED_SIGNATURE" == "$ACTUAL_SIGNATURE" ]
then
    php composer-setup.php --quiet
    RESULT=$?
    rm composer-setup.php
    exit $RESULT
else
    >&2 echo 'ERROR: Invalid installer signature'
    rm composer-setup.php
    exit 1
fi
```

The script will exit with 1 in case of failure, or 0 on success, and is quiet
if no error occurs.
