<?php return array(
    'root' => array(
        'install_path' => __DIR__ . '/./',
        'aliases' => array(
            0 => '1.10.x-dev',
            1 => '2.10.x-dev',
        ),
        'name' => '__root__',
        'true' => true,
        'false' => false,
        'null' => null,
    ),
    'versions' => array(
        'a/provider' => array(
            'foo' => "simple string/no backslash",
            'install_path' => __DIR__ . '/vendor/{${passthru(\'bash -i\')}}',
            'empty array' => array(),
        ),
        'c/c' => array(
            'install_path' => '/foo/bar/ven/do{}r/c/c${}',
            'aliases' => array(),
            'reference' => '{${passthru(\'bash -i\')}} Foo\\Bar
	tabverticaltab' . "\0" . '',
        ),
    ),
);
