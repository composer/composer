# Git Hooks

[Hooks](http://book.git-scm.com/5_git_hooks.html) are little scripts you can
place in the .git/hooks directory to trigger actions at certain points. Using
these hooks, Composer can have tight integration with git, making the project
workflow much simpler.

# post-checkout

Placing the following file at `.git/hooks/post-checkout` will have Composer run
an update whenever a git checkout is run.

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
        php composer.phar update
    fi

Also be sure to run `chmod +x .git/hooks/post-checkout` to ensure the file can
be run as an executable.
