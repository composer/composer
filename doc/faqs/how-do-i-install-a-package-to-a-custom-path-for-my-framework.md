# How do I install a package to a custom path for my framework?

Each framework may have one or many different required package installation
paths. Composer can be configured to install packages to a folder other than
the default `vendor` folder by using
[composer/installers](https://github.com/composer/installers).

If you are a **package author** and want your package installed to a custom
directory, require `composer/installers` and set the appropriate `type`.
Specifying the package type, will override the default installer path.
This is common if your package is intended for a specific framework such as
CakePHP, Drupal or WordPress. Here is an example composer.json file for a
WordPress theme:

```json
{
    "name": "you/themename",
    "type": "wordpress-theme",
    "require": {
        "composer/installers": "~1.0"
    }
}
```

Now when your theme is installed with Composer it will be placed into
`wp-content/themes/themename/` folder. Check the
[current supported types](https://github.com/composer/installers#current-supported-package-types)
for your package.

As a **package consumer** you can set or override the install path for a package
that requires composer/installers by configuring the `installer-paths` extra. A
useful example would be for a Drupal multisite setup where the package should be
installed into your site's subdirectory. Here we are overriding the install path
for a module that uses composer/installers, as well as putting all packages of type
`drupal-theme` into a themes folder:

```json
{
    "extra": {
        "installer-paths": {
            "sites/example.com/modules/{$name}": [
                "vendor/package"
            ],
            "sites/example.com/themes/{$name}": [
                "type:drupal-theme"
            ]
        }
    }
}
```

Now the package would be installed to your folder location, rather than the default
composer/installers determined location. In addition, `installer-paths` is
order-dependent, which means moving a package by name should come before the installer
path of a `type:*` that matches the same package.

> **Note:** You cannot use this to change the path of any package. This is only
> applicable to packages that require `composer/installers` and use a custom type
> that it handles.
