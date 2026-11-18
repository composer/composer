<!--
    tagline: Hosting and installing private Composer packages
-->

# Handling private packages

# Private Packagist

[Private Packagist](https://packagist.com/?utm_source=getcomposer.org&utm_medium=docs&utm_campaign=privatepackages)
is a commercial Composer repository service by the team behind Composer and
Packagist.org. It hosts your private packages and mirrors your third-party
dependencies, including their zip files, so installs are faster and you can deploy
even when GitHub or another source is down.

Private Packagist also:

- updates package information through webhooks from GitHub, GitLab and Bitbucket
  as soon as you push, without waiting for a rebuild
- gives every developer and CI environment its own authentication token, with
  access control per team and package, synchronized with your GitHub, GitLab or
  Bitbucket organization
- offers suborganizations: separate repositories for clients or projects, each
  with its own packages and access
- monitors your projects' `composer.lock` files for security advisories and alerts
  you by email, Slack or Microsoft Teams
- blocks malware-flagged package versions on the server for every Composer version,
  and can hold back new releases with a cooldown period
- comments on pull requests with a summary of all dependency changes
- shows which of your projects use a package, with install statistics and an
  overview of licenses
- lets you distribute packages to your customers, with a repository per customer
  and access limited by version or expiration date

Private Packagist is available as a cloud service or self-hosted on your own
infrastructure.

Part of Private Packagist's revenue funds the development and hosting of Composer
and Packagist.org. See
[packagist.com](https://packagist.com/?utm_source=getcomposer.org&utm_medium=docs&utm_campaign=privatepackagesmore)
for features, pricing and a free trial, and the
[Satis documentation](https://composer.github.io/satis/private-packagist) for a
comparison of Satis and Private Packagist.

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
