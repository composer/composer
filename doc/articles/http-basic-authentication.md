<!--
    tagline: How to use http basic authentication
-->

# Http basic authentication

Your [satis](handling-private-packages-with-satis.md) server could be
secured with http basic authentication. In order to allow your project
to have access to these packages you will have to tell composer how to
authenticate with your credentials.

The most simple way to provide your credentials is providing your set
of credentials inline with the repository specification such as:

    {
        "repositories": [
            {
                "type": "composer",
                "url": "http://extremely:secret@repo.example.org"
            }
        ]
    }

This will basically teach composer how to authenticate automatically
when reading packages from the provided composer repository.

This does not work for everybody especially when you don't want to
hard code your credentials into your composer.json. There is a second
way to provide these details and is via interaction. If you don't
provide the authentication credentials composer will prompt you upon
connection to enter the username and password.

There is yet another way to provide these details and is via a file
`auth.json` inside your `COMPOSER_HOME` which looks something like
`/Users/username/.composer/auth.json`

    {
        "basic-auth": [
            "repo.example1.org": {
                "username": "my-username1",
                "password": "my-secret-password1"
            },
            "repo.example2.org": {
                "username": "my-username2",
                "password": "my-secret-password2"
            }
        ]
    }

This then will provide http basic authentication for two domains
serving packages with two different sets of credentials.
