Contributing to Composer
========================

Please note that this project is released with a
[Contributor Code of Conduct](http://contributor-covenant.org/version/1/4/).
By participating in this project you agree to abide by its terms.

Reporting Issues
----------------

When reporting issues, please try to be as descriptive as possible, and include
as much relevant information as you can. A step by step guide on how to
reproduce the issue will greatly increase the chances of your issue being
resolved in a timely manner.

For example, if you are experiencing a problem while running one of the
commands, please provide full output of said command in very very verbose mode
(`-vvv`, e.g. `composer install -vvv`).

If your issue involves installing, updating or resolving dependencies, the
chance of us being able to reproduce your issue will be much higher if you
share your `composer.json` with us.

Coding Style Fixes
------------------

We do not accept CS fixes pull requests. Fixes are done by the project maintainers when appropriate to avoid causing too many unnecessary conflicts between branches and pull requests.

Security Reports
----------------

Please send any sensitive issue to [security@packagist.org](mailto:security@packagist.org). Thanks!

Installation from Source
------------------------

Prior to contributing to Composer, you must be able to run the test suite.
To achieve this, you need to acquire the Composer source code:

1. Run `git clone https://github.com/composer/composer.git`
2. Download the [`composer.phar`](https://getcomposer.org/composer.phar) executable
3. Run Composer to get the dependencies: `cd composer && php ../composer.phar install`

You can run the test suite by executing `vendor/bin/phpunit` when inside the
composer directory, and run Composer by executing the `bin/composer`. To test
your modified Composer code against another project, run `php
/path/to/composer/bin/composer` inside that project's directory.

Contributing policy
-------------------

Fork the project, create a feature branch, and send us a pull request.

To ensure a consistent code base, you should make sure the code follows
the [PSR-2 Coding Standards](http://www.php-fig.org/psr/psr-2/). You can also
run [php-cs-fixer](https://github.com/FriendsOfPHP/PHP-CS-Fixer) with the
configuration file that can be found in the project root directory.

If you would like to help, take a look at the [list of open issues](https://github.com/composer/composer/issues).
