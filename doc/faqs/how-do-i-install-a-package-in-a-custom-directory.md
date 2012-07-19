# How do I install a package in a custom directory?

Composer can be configured to install packages to a folder other than the
default `vendor` folder. A simple way is to use the
[composer/installers](https://github.com/composer/installers) package and if
you are using a framework, chances are a custom directory has been already
configured for you.

If you are a **package author** and want your package installed to a custom
directory, simply require `composer/installers` and set the appropriate `type`.
This is common if your package is intended for a specific framework such as
CakePHP, Drupal or WordPress. Here is an example composer.json file for a
WordPress theme:

```
{
    "name": "you/themename",
    "type": "wordpress-theme",
    "require": {
        "composer/installers": "*"
    }
}
```

Now when your theme is installed with Composer it will be placed into
`wp-content/themes/themename/` folder. Check the
[current supported types](https://github.com/composer/installers#current-supported-types)
for your package.

As a **package consumer** you can set or override the install path for each
package with the `installer-paths` extra. A useful example would be for a
Drupal multisite setup where the package should be installed into your sites
subdirectory. Here we are overriding the install path for a module that uses
composer/installers:

```
{
    "extra": {
        "installer-paths": {
            "sites/example.com/modules/{$name}": ["vendor/package"]
        }
    }
}
```

Now the package would be installed to your folder location, rather than the default
composer/installers determined location.
