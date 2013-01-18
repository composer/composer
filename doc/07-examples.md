# Examples of configuration files

## composer.json
Here will be a list of example configurations. Please feel free to use and extend them.

### Custom Repository + Classmap for Autoloading
In this example we do the following:
* add dependancies
* add a custom repository
* add classmap definitions

        {
        name": "vendor/project",
        "description": "project with composer",
        "authors": [{
            "name": "A Name",  
            "email": "test@test.com"  
        }]  
        ,"require": {  
            "vendor1/project1": "1.0.0",  
            "vendor1/project2": "1.0.0",  
            "vendor1/project3": "1.0.0"  
        }  
        ,"repositories": [  
        {  
            "type": "composer",  
            "url": "http://repository.yourdomain.com"  
        },  
        {  
            "packagist": false  
        }  
        ]  
        ,"autoload": {  
            "classmap":   
                ["./projectclasses/", "vendor/vendor1/project1/projectclasses/", "vendor/vendor1/project3/some/folder/projectclasses]  
            }  
        }


## packages.json-Example with includes

    {"packages": [],
        "includes":
        {
            "project1-packages.json": {"sha1": "970eef7d4bd08df2983c704e4c3ff47a2d47732c"},
            "project2-packages.json": {"sha1": "53331ba3f7feff8331d2d07862293864f16157fc"},
            "project3-packages.json": {"sha1": "8434fe9b6f4ca59ec3ed613ff67cf2a89195cc88"}
        }
    }


## Example packages.json include-file
This is an example of one of these packages-json-files from (see packages.json-Example with includes) if you use zip-files for distribution:

    {"packages":
        {
            "vendor1/project1":
            {"1.0.0.0":
                {
                "name": "vendor1/project1",
                "version": "1.0.0.0",
                "dist": {
                    "type": "zip",
                    "url": "http://repository.yourdomain.com/vendor/project1_1.0.0.0.zip",
                    "reference": "1.0.0.0"
                },
                "type":"library"
                }
            }
        }
    }

&larr; [Repositories](05-repositories.md)
