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

Fork the project, create a feature branch, and send us a pull request.

To ensure a consistent code base, you should make sure the code follows
the [PSR-2 Coding Standards](http://www.php-fig.org/psr/psr-2/).

If you would like to help, take a look at the [list of issues](http://github.com/composer/composer/issues).
