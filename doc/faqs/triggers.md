# Triggers

## What is a trigger?

A trigger is an event that runs a script in a static method, defined by a 
project. This event is raised before and after each action (install, update).


## Where are the event types defined?

It is in the constant property in `Composer\Trigger\TriggerEvents` class.


## How is it defined?

It is defined by adding the `triggers` key in the `extra` key to a project's
`composer.json` or package's `composer.json`.

It is specified as an array of classes with her static method,
in associative array define the event's type.

The PSR-0 must be defined, otherwise the trigger will not be triggered.

For any given project:

```json
{
    "extra": {
        "triggers": {
            "post_install": [
                "MyVendor\\MyRootPackage\\MyClass::myStaticMethod"
            ],
            "post_update": [
                "MyVendor\\MyRootPackage\\MyClass::myStaticMethod2"
            ]
        }
    },
    "autoload": {
        "psr-0": {
            "MyVendor\\MyRootPackage": "my/folder/path/that/contains/triggers/from/the/root/project"
        }
    }
}
```

Trigger Example:

```php
<?php
namespace MyVendor\MyRootPackage;

use Composer\Trigger\TriggerEvent;

class MyClass
{
    public static function myStaticMethod(TriggerEvent $event)
    {
        // code...
    }
    
    public static function myStaticMethod2(TriggerEvent $event)
    {
        // code...
    }
}
```

## Informations:

A declared trigger with non existent file or without registered namespace will be ignored.
