<!--
    tagline: Access privately hosted packages
-->

# HTTP basic authentication

Your [Satis or Toran Proxy](handling-private-packages-with-satis.md) server
could be secured with http basic authentication. In order to allow your project
to have access to these packages you will have to tell composer how to
authenticate with your credentials.

The simplest way to provide your credentials is providing your set
of credentials inline with the repository specification such as:

```json
{
    "repositories": [
        {
            "type": "composer",
            "url": "http://extremely:secret@repo.example.org"
        }
    ]
}
```

This will basically teach composer how to authenticate automatically
when reading packages from the provided composer repository.

This does not work for everybody especially when you don't want to
hard code your credentials into your composer.json. There is a second
way to provide these details and it is via interaction. If you don't
provide the authentication credentials composer will prompt you upon
connection to enter the username and password.

The third way if you want to pre-configure it is via an `auth.json` file
located in your `COMPOSER_HOME` or besides your `composer.json`.

The file should contain a set of hostnames followed each with their own
username/password pairs, for example:

```json
{
    "http-basic": {
        "repo.example1.org": {
            "username": "my-username1",
            "password": "my-secret-password1"
        },
        "repo.example2.org": {
            "username": "my-username2",
            "password": "my-secret-password2"
        }
    }
}
```

The main advantage of the auth.json file is that it can be gitignored so
that every developer in your team can place their own credentials in there,
which makes revokation of credentials much easier than if you all share the
same.
