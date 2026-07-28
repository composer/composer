<p align="center">
    <a href="https://getcomposer.org">
        <img src="https://getcomposer.org/img/logo-composer-transparent.png" alt="Composer">
    </a>
</p>
<h1 align="center">Dependency Management for PHP</h1>

Composer helps you declare, manage, and install dependencies of PHP projects.

See [https://getcomposer.org/](https://getcomposer.org/) for more information and documentation.

[![Continuous Integration](https://github.com/composer/composer/actions/workflows/continuous-integration.yml/badge.svg?branch=main)](https://github.com/composer/composer/actions/workflows/continuous-integration.yml?query=branch%3Amain)

Installation / Usage
--------------------

Download and install Composer by following the [official instructions](https://getcomposer.org/download/).

For usage, see [the documentation](https://getcomposer.org/doc/).

Packages
--------

Find public packages on [Packagist.org](https://packagist.org).

For private package hosting take a look at [Private Packagist](https://packagist.com).

Community
---------

Follow [@packagist](https://X.com/packagist) or [@seldaek](https://X.com/seldaek) on X for announcements, or check the [#composerphp](https://X.com/search?q=%23composerphp&src=typed_query&f=live) hashtag.

For support, Stack Overflow offers a good collection of
[Composer related questions](https://stackoverflow.com/questions/tagged/composer-php), or you can use the [GitHub discussions](https://github.com/composer/composer/discussions).

Please note that this project is released with a
[Contributor Code of Conduct](https://www.contributor-covenant.org/version/1/4/code-of-conduct/).
By participating in this project and its community you agree to abide by those terms.

Requirements
------------

#### Latest Composer

PHP 7.2.5 or above for the latest version.

#### Composer 2.2 LTS (Long Term Support)

PHP versions 5.3.2 - 8.1 are still supported via the LTS releases of Composer (2.2.x). If you
run the installer or the `self-update` command the appropriate Composer version for your PHP
should be automatically selected.

#### Binary dependencies

- `unzip` (or `7z`/`7zz`)
- `gzip`
- `tar`
- `unrar`
- `xz`
- Git (`git`)
- Mercurial (`hg`)
- Fossil (`fossil`)
- Perforce (`p4`)
- Subversion (`svn`)

The need for these binary dependencies may vary depending on individual use cases. For most users,
only 2 dependencies are essential for Composer: `unzip` (or `7z`/`7zz`), and `git`. If the
[`ext-zip`](https://www.php.net/manual/en/zip.installation.php) extension is available, only `git`
is needed, but this is not recommended.

Authors
-------

- Nils Adermann  | [GitHub](https://github.com/naderman)  | [X](https://X.com/naderman) | <naderman@naderman.de> | [naderman.de](https://naderman.de)
- Jordi Boggiano | [GitHub](https://github.com/Seldaek) | [X](https://X.com/seldaek) | <j.boggiano@seld.be> | [seld.be](https://seld.be)

See also the list of [contributors](https://github.com/composer/composer/contributors) who participated in this project.

Security Reports
----------------

Please send any sensitive issue to [security@packagist.org](mailto:security@packagist.org). Thanks!

Sponsors
--------

Thank you to our sponsors for supporting the ongoing development and maintenance of Composer and Packagist.org! See [packagist.org/sponsor](https://packagist.org/sponsor/) for more information on becoming a sponsor.

<p align="center">
    <a href="https://packagist.com/?utm_source=composer"><img src="https://packagist.org/img/private-packagist-dark.svg" alt="Private Packagist" height="60"></a>
    <a href="https://www.aikido.dev/?utm_source=composer"><img src="https://packagist.org/img/aikido-dark.svg" alt="Aikido" height="60"></a>
    <a href="https://www.sovereign.tech/?utm_source=composer"><img src="https://packagist.org/img/sovereign-tech-agency-dark.svg" alt="Sovereign Tech Fund" height="60"></a>
    <a href="https://aws.amazon.com/?utm_source=composer"><img src="https://packagist.org/img/aws-dark.svg" alt="AWS" height="60"></a>
    <a href="https://socket.dev/?utm_source=composer"><img src="https://packagist.org/img/socket-dark.svg" alt="Socket" height="60"></a>
    <a href="https://upsun.com/?utm_source=composer"><img src="https://packagist.org/img/upsun-dark.svg" alt="Upsun" height="60"></a>
    <a href="https://bunny.net/?utm_source=composer"><img src="https://packagist.org/img/bunny-net-dark.svg" alt="Bunny.net" height="60"></a>
    <a href="https://tideways.com/?utm_source=composer"><img src="https://packagist.org/img/tideways-dark.svg" alt="Tideways" height="60"></a>
    <a href="https://datadog.com/?utm_source=composer"><img src="https://packagist.org/img/datadog-dark.svg" alt="Datadog" height="60"></a>
    <a href="https://www.algolia.com/?utm_source=composer"><img src="https://packagist.org/img/algolia-dark.svg" alt="Algolia" height="60"></a>
</p>

License
-------

Composer is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

Acknowledgments
---------------

- This project's Solver started out as a PHP port of openSUSE's
  [Libzypp satsolver](https://en.opensuse.org/openSUSE:Libzypp_satsolver).
