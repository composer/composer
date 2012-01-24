# Triggers

## What is a trigger?

A trigger is an event that runs a script in a static method, defined by a 
package or project. This event is raised before and after each action (install, 
update).


## Where are the event types defined?

It is in the constant property in `Composer\Trigger\TriggerEvents` class.


## How is it defined?

It is defined by adding the `triggers` key in the `extra` key to a project's
`composer.json` or package's `composer.json`.

It is specified as an associative array of classes with her static method,
associated with the event's type.

The PSR-0 must be defined, otherwise the trigger will not be triggered.

For any given package:

```json
{
    "extra": {
        "triggers": {
            "MyVendor\MyPackage\MyClass::myStaticMethod" : "post_install",
            "MyVendor\MyPackage\MyClass::myStaticMethod2" : "post_update",
        }
    },
    "autoload": {
        "psr-0": {
            "MyVendor\MyPackage": ""
        }
    }
}
```

For any given project:
```json
{
    "extra": {
        "triggers": {
            "MyVendor\MyPackage2\MyClass2::myStaticMethod2" : "post_install",
            "MyVendor\MyPackage2\MyClass2::myStaticMethod3" : "post_update",
        }
    },
    "autoload": {
        "psr-0": {
            "MyVendor\MyPackage": "my/folder/path/that/contains/triggers/from/the/root/project"
        }
    }
}
```

## Informations:

The project's triggers are executed after the package's triggers.
A declared trigger with non existent file will be ignored.

For example:
If you declare a trigger for a package pre install, as this trigger isn't 
downloaded yet, it won't run.

On the other hand, if you declare a pre-update package trigger, as the file 
already exist, the actual vendor's version of the trigger will be run.
