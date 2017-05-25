<!--
    tagline: Execute Composer installs when interacting with git.
-->
# Git Hooks

[Hooks](http://book.git-scm.com/5_git_hooks.html) are little scripts you can
place in the `.git/hooks` directory to trigger actions at certain points. Using
these hooks Composer can have tight integration with git thereby easing the
project workflow.

## post-checkout

To have Composer install all its dependencies whenever a git checkout is
performed, place the following file at `.git/hooks/post-checkout`.

    #!/bin/sh
    # Composer Git Checkout Hook
    
    # Process composer.json if one exists.
    if [ -f composer.json ]
    then
        # Check to see if Composer is installed.
        echo "Processing Composer"
    
        # Check to see if Composer is installed.
        [ ! -f composer.phar ] && [ ! `which composer.phar` ] && curl -s http://getcomposer.org/installer | php >/dev/null
    
        # Run the composer install
        [ -f composer.phar ] && php composer.phar install || composer.phar install
    fi

Also be sure to run `chmod +x .git/hooks/post-checkout` to ensure the file can
be run as an executable.
