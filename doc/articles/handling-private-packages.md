<!--
    tagline: Hosting and installing private Composer packages
-->

# Handling private packages

# Private Packagist

[Private Packagist](https://packagist.com) is a commercial package hosting product
offering professional support and web based management of private and public packages,
and granular access permissions. Private Packagist provides mirroring for packages' zip
files which makes installs faster and independent from third party systems - e.g.
you can deploy even if GitHub is down because your zip files are mirrored.

Private Packagist is available as a hosted SaaS solution or as an on-premise self-hosted
package, providing an interactive set up experience.

Some of Private Packagist's revenue is used to pay for Composer and Packagist.org
development and hosting so using it is a good way to support the maintenance of
these open source projects financially. You can find more information about how to
set up your own package archive on [Packagist.com](https://packagist.com).

# Satis

[Satis](https://composer.github.io/satis/) is an open source static `composer`
repository generator. It is a bit like an ultra-lightweight, static file-based
version of packagist and can be used to host the metadata of your company's
private packages, or your own. See the [Satis documentation](https://composer.github.io/satis/)
for how to install, configure and run it.

# Using a private repository

In your projects all you need to add is your own Composer repository URL,
then you can require your private packages and everything should work smoothly.
You don't need to copy all your repositories in every project anymore. Only that
one unique repository that will update itself.

```json
{
    "repositories": [ { "type": "composer", "url": "http://packages.example.org/" } ],
    "require": {
        "company/package": "1.2.0",
        "company/package2": "1.5.2",
        "company/package3": "dev-master"
    }
}
```

## Security

To secure your private repository you can host it over SSH or SSL using a client
certificate. In your project you can use the `options` parameter to specify the
connection options for the server.

Example using a custom repository using SSH (requires the SSH2 PECL extension):

```json
{
    "repositories": [{
        "type": "composer",
        "url": "ssh2.sftp://example.org",
        "options": {
            "ssh2": {
                "username": "composer",
                "pubkey_file": "/home/composer/.ssh/id_rsa.pub",
                "privkey_file": "/home/composer/.ssh/id_rsa"
            }
        }
    }]
}
```

> **Tip:** See [ssh2 context options](https://secure.php.net/manual/en/wrappers.ssh2.php#refsect1-wrappers.ssh2-options) for more information.

Example using SSL/TLS (HTTPS) using a client certificate:

```json
{
    "repositories": [{
         "type": "composer",
         "url": "https://example.org",
         "options": {
             "ssl": {
                 "local_cert": "/home/composer/.ssl/composer.pem"
             }
         }
    }]
}
```

> **Tip:** See [ssl context options](https://secure.php.net/manual/en/context.ssl.php) for more information.

Example using a custom HTTP Header field for token authentication:

```json
{
    "repositories": [{
        "type": "composer",
        "url": "https://example.org",
        "options":  {
            "http": {
                "header": [
                    "API-TOKEN: YOUR-API-TOKEN"
                ]
            }
        }
    }]
}
```

## Authentication

Authentication can be handled in [several different ways](authentication-for-private-packages.md).
