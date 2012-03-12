# Create Projects

You can use Composer to create new projects from an existing package.
There are several applications for this:

1. You can deploy application packages.
2. You can check out any package and start developing on patches for example.
3. Projects with multiple developers can use this feature to bootstrap the initial application for development.

To create a new project using composer you can use the "create-project" command.
Pass it a package name, and the directory to create the project in. You can also
provide a version as third argument, otherwise the latest version is used.

The directory is not allowed to exist, it will be created during installation.

    php composer.phar create-project doctrine/orm path 2.2.0

By default the command checks for the packages on packagist.org. To change this behavior
you can use the --repository-url parameter and either point it to an HTTP url
for your own packagist repository or to a packages.json file.

If you want to get a development version of the code directly checked out
from version control you have to add the --prefer-source parameter.
