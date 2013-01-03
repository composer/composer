<!--
    tagline: Host your own composer repository
-->

# Handling private packages with Satis

Satis can be used to host the metadata of your company's private packages, or
your own. It basically acts as a micro-packagist. You can get it from
[GitHub](http://github.com/composer/satis) or install via CLI:
`composer.phar create-project composer/satis`.

## Setup

For example let's assume you have a few packages you want to reuse across your
company but don't really want to open-source. You would first define a Satis
configuration file, which is basically a stripped-down version of a
`composer.json` file. It contains a few repositories, and then you use the require
key to say which packages it should dump in the static repository it creates, or
use require-all to select all of them.

Here is an example configuration, you see that it holds a few VCS repositories,
but those could be any types of [repositories](../05-repositories.md). Then it
uses `"require-all": true` which selects all versions of all packages in the
repositories you defined.

    {
        "name": "My Repository",
        "homepage": "http://packages.example.org",
        "repositories": [
            { "type": "vcs", "url": "http://github.com/mycompany/privaterepo" },
            { "type": "vcs", "url": "http://svn.example.org/private/repo" },
            { "type": "vcs", "url": "http://github.com/mycompany/privaterepo2" }
        ],
        "require-all": true
    }

If you want to cherry pick which packages you want, you can list all the packages
you want to have in your satis repository inside the classic composer `require` key,
using a `"*"` constraint to make sure all versions are selected, or another
constraint if you want really specific versions.

    {
        "repositories": [
            { "type": "vcs", "url": "http://github.com/mycompany/privaterepo" },
            { "type": "vcs", "url": "http://svn.example.org/private/repo" },
            { "type": "vcs", "url": "http://github.com/mycompany/privaterepo2" }
        ],
        "require": {
            "company/package": "*",
            "company/package2": "*",
            "company/package3": "2.0.0"
        }
    }

Once you did this, you just run `php bin/satis build <configuration file> <build dir>`.
For example `php bin/satis build config.json web/` would read the `config.json`
file and build a static repository inside the `web/` directory.

When you ironed out that process, what you would typically do is run this
command as a cron job on a server. It would then update all your package info
much like Packagist does.

Note that if your private packages are hosted on GitHub, your server should have
an ssh key that gives it access to those packages, and then you should add
the `--no-interaction` (or `-n`) flag to the command to make sure it falls back
to ssh key authentication instead of prompting for a password. This is also a
good trick for continuous integration servers.

Set up a virtual-host that points to that `web/` directory, let's say it is
`packages.example.org`.

## Usage

In your projects all you need to add now is your own composer repository using
the `packages.example.org` as URL, then you can require your private packages and
everything should work smoothly. You don't need to copy all your repositories
in every project anymore. Only that one unique repository that will update
itself.

    {
        "repositories": [ { "type": "composer", "url": "http://packages.example.org/" } ],
        "require": {
            "company/package": "1.2.0",
            "company/package2": "1.5.2",
            "company/package3": "dev-master"
        }
    }

### Security

To secure your private repository you can host it over SSH or SSL using a client
certificate. In your project you can use the `options` parameter to specify the
connection options for the server.

Example using a custom repository using SSH (requires the SSH2 PECL extension):

    {
        "repositories": [
            {
                "type": "composer",
                "url": "ssh2.sftp://example.org",
                "options": {
                    "ssh2": {
                        "username": "composer",
                        "pubkey_file": "/home/composer/.ssh/id_rsa.pub",
                        "privkey_file": "/home/composer/.ssh/id_rsa"
                    }
                }
            }
        ]
    }

Example using HTTP over SSL using a client certificate:

    {
        "repositories": [
            {
                "type": "composer",
                "url": "https://example.org",
                "options": {
                    "ssl": {
                        "cert_file": "/home/composer/.ssl/composer.pem",
                    }
                }
            }
        ]
    }
