--TEST--
Updates packages to their lowest stable version
--COMPOSER--
{
    "repositories": [
        {
            "type": "package",
            "package": [
                { "name": "a/a", "version": "1.0.0-rc1" },
                { "name": "a/a", "version": "1.0.1" },
                { "name": "a/a", "version": "1.1.0" },

                { "name": "a/b", "version": "1.0.0" },
                { "name": "a/b", "version": "1.0.1" },
                { "name": "a/b", "version": "2.0.0" },

                { "name": "a/c", "version": "1.0.0" },
                { "name": "a/c", "version": "2.0.0" }
            ]
        }
    ],
    "require": {
        "a/a": "~1.0@dev",
        "a/c": "2.*"
    },
    "require-dev": {
        "a/b": "*"
    }
}
--INSTALLED--
[
    { "name": "a/a", "version": "1.0.0-rc1" },
    { "name": "a/c", "version": "2.0.0" },
    { "name": "a/b", "version": "1.0.1" }
]
--RUN--
update --prefer-lowest --prefer-stable
--EXPECT--
Updating a/a (1.0.0-rc1) to a/a (1.0.1)
Updating a/b (1.0.1) to a/b (1.0.0)
