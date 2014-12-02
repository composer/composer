Composer - Dependency Management for PHP
========================================

Composer helps you declare, manage and install dependencies of PHP projects, ensuring you have the right stack everywhere.

See [https://getcomposer.org/](https://getcomposer.org/) for more information and documentation.

[![Build Status](https://travis-ci.org/composer/composer.svg?branch=master)](https://travis-ci.org/composer/composer)

Installation / Usage
--------------------

1. Download the [`composer.phar`](https://getcomposer.org/composer.phar) executable or use the installer.

    ``` sh
    $ curl -sS https://getcomposer.org/installer | php
    ```

2. Create a composer.json defining your dependencies. Note that this example is
a short version for applications that are not meant to be published as packages
themselves. To create libraries/packages please read the
[documentation](http://getcomposer.org/doc/02-libraries.md).

    ``` json
    {
        "require": {
            "monolog/monolog": ">=1.0.0"
        }
    }
    ```

3. Run Composer: `php composer.phar install`
4. Browse for more packages on [Packagist](https://packagist.org).

Installation from Source
------------------------

To run tests, or develop Composer itself, you must use the sources and not the phar
file as described above.

1. Run `git clone https://github.com/composer/composer.git`
2. Download the [`composer.phar`](https://getcomposer.org/composer.phar) executable
3. Run Composer to get the dependencies: `cd composer && php ../composer.phar install`

You can now run Composer by executing the `bin/composer` script: `php /path/to/composer/bin/composer`

Global installation of Composer (manual)
----------------------------------------

Follow instructions [in the documentation](http://getcomposer.org/doc/00-intro.md#globally)

Updating Composer
-----------------

Running `php composer.phar self-update` or equivalent will update a phar
install with the latest version.

Contributing
------------

All code contributions - including those of people having commit access -
must go through a pull request and approved by a core developer before being
merged. This is to ensure proper review of all the code.

Fork the project, create a feature branch, and send us a pull request.

To ensure a consistent code base, you should make sure the code follows
the [Coding Standards](http://symfony.com/doc/current/contributing/code/standards.html)
which we borrowed from Symfony.

If you would like to help take a look at the [list of issues](http://github.com/composer/composer/issues).

Community
---------

Mailing lists for [user support](http://groups.google.com/group/composer-users) and
[development](http://groups.google.com/group/composer-dev).

IRC channels are on irc.freenode.org: [#composer](irc://irc.freenode.org/composer)
for users and [#composer-dev](irc://irc.freenode.org/composer-dev) for development.

Stack Overflow has a growing collection of
[Composer related questions](http://stackoverflow.com/questions/tagged/composer-php).

Requirements
------------

PHP 5.3.2 or above (at least 5.3.4 recommended to avoid potential bugs)

Authors
-------

Nils Adermann - <naderman@naderman.de> - <http://twitter.com/naderman> - <http://www.naderman.de><br />
Jordi Boggiano - <j.boggiano@seld.be> - <http://twitter.com/seldaek> - <http://seld.be><br />

See also the list of [contributors](https://github.com/composer/composer/contributors) who participated in this project.

License
-------

Composer is licensed under the MIT License - see the LICENSE file for details

Acknowledgments
---------------

- This project's Solver started out as a PHP port of openSUSE's
  [Libzypp satsolver](http://en.opensuse.org/openSUSE:Libzypp_satsolver).
- This project uses hiddeninput.exe to prompt for passwords on windows, sources
  and details can be found on the [github page of the project](https://github.com/Seldaek/hidden-input).
