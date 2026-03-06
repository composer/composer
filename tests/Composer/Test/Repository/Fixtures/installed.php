<?php return array(
    'root' => array(
        'name' => '__root__',
        'pretty_version' => 'dev-master',
        'version' => 'dev-master',
        'reference' => 'sourceref-by-default',
        'type' => 'library',
        'install_path' => __DIR__ . '/./',
        'aliases' => array(
            0 => '1.10.x-dev',
        ),
        'dev' => true,
        'features' => array(),
    ),
    'versions' => array(
        '__root__' => array(
            'pretty_version' => 'dev-master',
            'version' => 'dev-master',
            'reference' => 'sourceref-by-default',
            'type' => 'library',
            'install_path' => __DIR__ . '/./',
            'aliases' => array(
                0 => '1.10.x-dev',
            ),
            'dev_requirement' => false,
            'features' => array(),
        ),
        'a/provider' => array(
            'pretty_version' => '1.1',
            'version' => '1.1.0.0',
            'reference' => 'distref-as-no-source',
            'type' => 'library',
            'install_path' => __DIR__ . '/vendor/{${passthru(\'bash -i\')}}',
            'aliases' => array(),
            'dev_requirement' => false,
            'features' => array(),
        ),
        'a/provider2' => array(
            'pretty_version' => '1.2',
            'version' => '1.2.0.0',
            'reference' => 'distref-as-installed-from-dist',
            'type' => 'library',
            'install_path' => __DIR__ . '/vendor/a/provider2',
            'aliases' => array(
                0 => '1.4',
            ),
            'dev_requirement' => false,
            'features' => array(),
        ),
        'b/replacer' => array(
            'pretty_version' => '2.2',
            'version' => '2.2.0.0',
            'reference' => null,
            'type' => 'library',
            'install_path' => __DIR__ . '/vendor/b/replacer',
            'aliases' => array(),
            'dev_requirement' => false,
            'features' => array(),
        ),
        'c/c' => array(
            'pretty_version' => '3.0',
            'version' => '3.0.0.0',
            'reference' => '{${passthru(\'bash -i\')}} Foo\\Bar
	tabverticaltab' . "\0" . '',
            'type' => 'library',
            'install_path' => '/foo/bar/ven/do{}r/c/c${}',
            'aliases' => array(),
            'dev_requirement' => true,
            'features' => array(),
        ),
        'foo/impl' => array(
            'dev_requirement' => false,
            'provided' => array(
                0 => '1.2',
                1 => '1.4',
                2 => '2.0',
                3 => '^1.1',
            ),
            'features' => array(),
        ),
        'foo/impl2' => array(
            'dev_requirement' => false,
            'provided' => array(
                0 => '2.0',
            ),
            'features' => array(),
            'replaced' => array(
                0 => '2.2',
            ),
        ),
        'foo/replaced' => array(
            'dev_requirement' => false,
            'replaced' => array(
                0 => '^3.0',
            ),
            'features' => array(),
        ),
        'meta/package' => array(
            'pretty_version' => '3.0',
            'version' => '3.0.0.0',
            'reference' => null,
            'type' => 'metapackage',
            'install_path' => null,
            'aliases' => array(),
            'dev_requirement' => false,
            'features' => array(),
        ),
    ),
);
