# How do I install untrusted packages safely? Is it safe to run Composer as superuser or root?

Certain Composer commands, including `exec`, `install`, and `update` allow third party code to
execute on your system. This is from its "plugins" and "scripts" features. Plugins and scripts have
full access to the user account which runs Composer. For this reason, it is strongly advised to
**avoid running Composer as super-user/root**.

You can disable plugins and scripts during package installation or updates with the following
syntax so only Composer's code, and no third party code, will execute:

```sh
composer install --no-plugins --no-scripts ...
composer update --no-plugins --no-scripts ...
```

The `exec` command will always run third party code as the user which runs `composer`.

In some cases, like in CI systems or such where you want to install untrusted dependencies, the
safest way to do it is to run the above command.
