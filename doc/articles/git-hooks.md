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
        if [ ! -f composer.phar ]
        then
            # Install Composer.
            curl -s http://getcomposer.org/installer | php
        fi
    
        # Update the project with Composer.
        php composer.phar install
    fi

Also be sure to run `chmod +x .git/hooks/post-checkout` to ensure the file can
be run as an executable.