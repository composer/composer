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

Follow [@packagist](https://twitter.com/packagist) or [@seldaek](https://twitter.com/seldaek) on Twitter for announcements, or check the [#composerphp](https://twitter.com/search?q=%23composerphp&src=typed_query&f=live) hashtag.

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

- `7z` (or `7zz`)
- `unzip` (if `7z` is missing)
- `gzip`
- `tar`
- `unrar`
- `xz`
- Git (`git`)
- Mercurial (`hg`)
- Fossil (`fossil`)
- Perforce (`p4`)
- Subversion (`svn`)

It's important to note that the need for these binary dependencies may vary
depending on individual use cases. However, for most users, only 2 dependencies
are essential for Composer: `7z` (or `7zz` or `unzip`), and `git`.

Authors
-------

- Nils Adermann  | [GitHub](https://github.com/naderman)  | [Twitter](https://twitter.com/naderman) | <naderman@naderman.de> | [naderman.de](https://naderman.de)
- Jordi Boggiano | [GitHub](https://github.com/Seldaek) | [Twitter](https://twitter.com/seldaek) | <j.boggiano@seld.be> | [seld.be](https://seld.be)

See also the list of [contributors](https://github.com/composer/composer/contributors) who participated in this project.

Security Reports
----------------

Please send any sensitive issue to [security@packagist.org](mailto:security@packagist.org). Thanks!

License
-------

Composer is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

Acknowledgments
---------------

- This project's Solver started out as a PHP port of openSUSE's
  [Libzypp satsolver](https://en.opensuse.org/openSUSE:Libzypp_satsolver).
