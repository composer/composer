<!--
    tagline: How to reduce the performance impact of the autoloader
-->

# Autoloader Optimization

By default, the Composer autoloader runs relatively fast. However, due to the way
PSR-4 and PSR-0 autoloading rules are set up, it needs to check the filesystem
before resolving a classname conclusively. This slows things down quite a bit,
but it is convenient in development environments because when you add a new class
it can immediately be discovered/used without having to rebuild the autoloader
configuration.

The problem however is in production you generally want things to happen as fast
as possible, as you can simply rebuild the configuration every time you deploy and
new classes do not appear at random between deploys.

For this reason, Composer offers a few strategies to optimize the autoloader.

> **Note:** You **should not** enable any of these optimizations in **development** as
> they all will cause various problems when adding/removing classes. The performance
> gains are not worth the trouble in a development setting.

## Optimization Level 1: Class map generation

### How to run it?

There are a few options to enable this:

- Set `"optimize-autoloader": true` inside the config key of composer.json
- Call `install` or `update` with `-o` / `--optimize-autoloader`
- Call `dump-autoload` with `-o` / `--optimize`

### What does it do?

Class map generation essentially converts PSR-4/PSR-0 rules into classmap rules.
This makes everything quite a bit faster as for known classes the class map
returns instantly the path, and Composer can guarantee the class is in there so
there is no filesystem check needed.

On PHP 5.6+, the class map is also cached in opcache which improves the initialization
time greatly. If you make sure opcache is enabled, then the class map should load
almost instantly and then class loading is fast.

### Trade-offs

There are no real trade-offs with this method. It should always be enabled in
production.

The only issue is it does not keep track of autoload misses (i.e. when
it can not find a given class), so those fallback to PSR-4 rules and can still
result in slow filesystem checks. To solve this issue two Level 2 optimization
options exist, and you can decide to enable either if you have a lot of
class_exists checks that are done for classes that do not exist in your project.

## Optimization Level 2/A: Authoritative class maps

### How to run it?

There are a few options to enable this:

- Set `"classmap-authoritative": true` inside the config key of composer.json
- Call `install` or `update` with `-a` / `--classmap-authoritative`
- Call `dump-autoload` with `-a` / `--classmap-authoritative`

### What does it do?

Enabling this automatically enables Level 1 class map optimizations.

This option is very simple, it says that if something is not found in the classmap,
then it does not exist and the autoloader should not attempt to look on the
filesystem according to PSR-4 rules.

### Trade-offs

This option makes the autoloader always return very quickly. On the flipside it
also means that in case a class is generated at runtime for some reason, it will
not be allowed to be autoloaded. If your project or any of your dependencies does that
then you might experience "class not found" issues in production. Enable this with care.

> Note: This can not be combined with Level 2/B optimizations. You have to choose one as
> they address the same issue in different ways.

## Optimization Level 2/B: APCu cache

### How to run it?

There are a few options to enable this:

- Set `"apcu-autoloader": true` inside the config key of composer.json
- Call `install` or `update` with `--apcu-autoloader`
- Call `dump-autoload` with `--apcu`

### What does it do?

This option adds an APCu cache as a fallback for the class map. It will not
automatically generate the class map though, so you should still enable Level 1
optimizations manually if you so desire.

Whether a class is found or not, that fact is always cached in APCu so it can be
returned quickly on the next request.

### Trade-offs

This option requires APCu which may or may not be available to you. It also
uses APCu memory for autoloading purposes, but it is safe to use and can not
result in classes not being found like the authoritative class map
optimization above.

> Note: This can not be combined with Level 2/A optimizations. You have to choose one as
> they address the same issue in different ways.
