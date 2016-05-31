# How to I install untrusted packages safely? Is it safe to run Composer as superuser or root?

Composer has a plugin system, and plugins are enabled automatically when installed. This means that
they can theoretically be used as an attack vector, and you should not blindly trust any package you
install. For this reason, it is strongly advised to **avoid running Composer as super-user/root**.

In some cases, like in CI systems or such where you want to install dependencies blindly, the safest
way to do it is to run `composer install --no-plugins --no-scripts`. This basically disables plugins
and scripts from executing, so that only Composer's code will run.
