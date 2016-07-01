<? // intentionally using php short tags

namespace Foo\Bar;

// Test for issue #3230, https://github.com/composer/composer/issues/3230

// causes "class controls" to be mapped
if(!class_exists("Document")){
    /**
     * This class controls the Document.
     **/
    class Document extends Tree{ }
}

// causes "class properties" to be mapped
class User{
    /**
     * User checks user credentials and updates class properties if successful.
     */
    public function login() { }
}
