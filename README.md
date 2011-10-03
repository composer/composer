Composer - Package Management for PHP
=====================================

Composer is a package manager tracking local dependencies of your projects and libraries.

See the [about page](http://packagist.org/about) on [packagist.org](http://packagist.org/) for more information.

Installation / Usage
--------------------

1. Download the [`composer.phar`](http://packagist.org/get/composer.phar) executable
2. Create a composer.json defining your dependencies. Note that this example is
a short version for applications that are not meant to be published as packages
themselves. To create libraries/packages please read the [guidelines](http://packagist.org/about).

    ``` json
    {
        "require": {
            "monolog/monolog": ">=1.0.0"
        }
    }
    ```

3. Run Composer: `php composer.phar install`
4. Browse for more packages on [Packagist](http://packagist.org).

Requirements
------------

PHP 5.3+

Authors
-------

Nils Adermann - <naderman@naderman.de> - <http://twitter.com/naderman> - <http://www.naderman.de><br />
Jordi Boggiano - <j.boggiano@seld.be> - <http://twitter.com/seldaek> - <http://seld.be><br />

See also the list of [contributors](https://github.com/composer/composer/contributors) who participated in this project.

License
-------

Composer is licensed under the MIT License - see the LICENSE file for details

Acknowledgements
----------------

This project's Solver started out as a PHP port of openSUSE's [Libzypp satsolver](http://en.opensuse.org/openSUSE:Libzypp_satsolver).
