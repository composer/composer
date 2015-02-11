Contributing to Composer
========================

Installation from Source
------------------------

Prior to contributing to Composer, you must use be able to run the tests.
To achieve this, you must use the sources and not the phar file.

1. Run `git clone https://github.com/composer/composer.git`
2. Download the [`composer.phar`](https://getcomposer.org/composer.phar) executable
3. Run Composer to get the dependencies: `cd composer && php ../composer.phar install`

You can now run Composer by executing the `bin/composer` script: `php /path/to/composer/bin/composer`

Contributing policy
-------------------

All code contributions - including those of people having commit access -
must go through a pull request and approved by a core developer before being
merged. This is to ensure proper review of all the code.

Fork the project, create a feature branch, and send us a pull request.

To ensure a consistent code base, you should make sure the code follows
the [Coding Standards](http://symfony.com/doc/current/contributing/code/standards.html)
which we borrowed from Symfony.

If you would like to help, take a look at the [list of issues](http://github.com/composer/composer/issues).
