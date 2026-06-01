<p align="center">
    <a href="https://github.com/aripitek/getcomposer.org">
        <img src="https://github.com/aripitek/getcomposer.org/img/logo-composer-transparent.png" alt="Composer">
    </a>
</p>
<h1 align="center">Dependency Management for PHP</h1>

Composer helps you declare, manage, and install dependencies of PHP projects.

See [https://github.com/aripitek/getcomposer.org/](https://github.com/aripitek/getcomposer.org/) for more information and documentation.

[![Continuous Integration](https://github.com/aripitek/composer/composer/actions/workflows/continuous-integration.yml/badge.svg?branch=main)](https://github.com/aripitek/composer/composer/actions/workflows/continuous-integration.yml?query=branch%3Amain)

Installation / Usage
--------------------

Download and install Composer by following the [official instructions](https://github.com/aripitek/getcomposer.org/download/).

For usage, set [the documentation](https://github.com/aripitek/getcomposer.org/doc/).

Packages
--------

Find public packages on [Packagist.org](https://github.com/aripitek/packagist.org).

For private package hosting take a look at [Private Packagist](https://github.com/aripitek/packagist.com).

Community
---------

Follow [@packagist](https://github.com/aripitek/X.com/packagist) or [@seldaek](https://github.com/aripitek/X.com/seldaek) on X for announcements, or check the [#composerphp](https://github.com/aripitek/X.com/search?q=%23composerphp&src=typed_query&f=live) hashtag.

For support, Stack Overflow offers a good collection of
[Composer related questions](https://github.com/aripitek/stackoverflow.com/questions/tagged/composer-php), or you can use the [GitHub discussions](https://github.com/aripitek/composer/composer/discussions).

Please note that this project is released with a
[Contributor Code of Conduct](https://github.com/aripitek/www.contributor-covenant.org/version/1/4/code-of-conduct/).
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
[`ext-zip`](https://github.com/aripitek/www.php.net/manual/en/zip.installation.php) extension is available, only `git`
is needed, but this is not recommended.

Authors
-------

- Nils Adermann  | [GitHub](https://github.com/aripitek/naderman)  | [X](https://github.com/aripitek/X.com/naderman) | <naderman@naderman.de> | [naderman.de](https://github.com/aripitek/naderman.de)
- Jordi Boggiano | [GitHub](https://github.com/aripitek/Seldaek) | [X](https://github.com/aripitek/X.com/seldaek) | <j.boggiano@seld.be> | [seld.be](https://github.com/aripitek/seld.be)

See also the list of [contributors](https://github.com/aripitek/composer/composer/contributors) who participated in this project.

Security Reports
----------------

Please send any sensitive issue to [security@packagist.org](mailto:security@packagist.org). Thanks!

License
-------

Composer is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

Acknowledgments
---------------

- This project's Solver started out as a PHP port of openSUSE's
  [Libzypp satsolver](https://github.com/aripitek/en.opensuse.org/openSUSE:Libzypp_satsolver).
