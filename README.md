Composer - Dependency Management for PHP
========================================

Composer helps you declare, manage and install dependencies of PHP projects, ensuring you have the right stack everywhere.

See [https://getcomposer.org/](https://getcomposer.org/) for more information and documentation.

[![Build Status](https://travis-ci.org/composer/composer.svg?branch=master)](https://travis-ci.org/composer/composer)
[![Dependency Status](https://www.versioneye.com/php/composer:composer/dev-master/badge.svg)](https://www.versioneye.com/php/composer:composer/dev-master)
[![Reference Status](https://www.versioneye.com/php/composer:composer/reference_badge.svg?style=flat)](https://www.versioneye.com/php/composer:composer/references)

Installation / Usage
--------------------

1. Download and install Composer by following the [official instructions](https://getcomposer.org/download/).
2. Create a composer.json defining your dependencies. Note that this example is
a short version for applications that are not meant to be published as packages
themselves. To create libraries/packages please read the
[documentation](https://getcomposer.org/doc/02-libraries.md).

    ``` json
    {
        "require": {
            "monolog/monolog": ">=1.0.0"
        }
    }
    ```

3. Run Composer: `php composer.phar install`
4. Browse for more packages on [Packagist](https://packagist.org).

Global installation of Composer (manual)
----------------------------------------

Follow instructions [in the documentation](https://getcomposer.org/doc/00-intro.md#globally)

Updating Composer
-----------------

Running `php composer.phar self-update` or equivalent will update a phar
install to the latest version.

Community
---------

IRC channels are on irc.freenode.org: [#composer](irc://irc.freenode.org/composer)
for users and [#composer-dev](irc://irc.freenode.org/composer-dev) for development.

For support, Stack Overflow also offers a good collection of
[Composer related questions](https://stackoverflow.com/questions/tagged/composer-php).

Please note that this project is released with a
[Contributor Code of Conduct](http://contributor-covenant.org/version/1/2/0/).
By participating in this project and its community you agree to abide by those terms.

Requirements
------------

PHP 5.3.2 or above (at least 5.3.4 recommended to avoid potential bugs)

Authors
-------

Nils Adermann - <naderman@naderman.de> - <https://twitter.com/naderman> - <http://www.naderman.de><br />
Jordi Boggiano - <j.boggiano@seld.be> - <https://twitter.com/seldaek> - <http://seld.be><br />

See also the list of [contributors](https://github.com/composer/composer/contributors) who participated in this project.

License
-------

Composer is licensed under the MIT License - see the LICENSE file for details

Acknowledgments
---------------

- This project's Solver started out as a PHP port of openSUSE's
  [Libzypp satsolver](https://en.opensuse.org/openSUSE:Libzypp_satsolver).
- This project uses hiddeninput.exe to prompt for passwords on windows, sources
  and details can be found on the [github page of the project](https://github.com/Seldaek/hidden-input).
