<?php return array(
    'root' => array(
        'name' => '__root__',
        'pretty_version' => 'dev-master',
        'version' => 'dev-master',
        'reference' => 'sourceref-by-default',
        'type' => 'library',
        // @phpstan-ignore variable.undefined
        'install_path' => $dir . '/./',
        'aliases' => array(
            '1.10.x-dev',
        ),
        'dev' => true,
    ),
    'versions' => array(
        '__root__' => array(
            'pretty_version' => 'dev-master',
            'version' => 'dev-master',
            'reference' => 'sourceref-by-default',
            'type' => 'library',
            // @phpstan-ignore variable.undefined
            'install_path' => $dir . '/./',
            'aliases' => array(
                '1.10.x-dev',
            ),
            'dev_requirement' => false,
        ),
        'a/provider' => array(
            'pretty_version' => '1.1',
            'version' => '1.1.0.0',
            'reference' => 'distref-as-no-source',
            'type' => 'library',
            // @phpstan-ignore variable.undefined
            'install_path' => $dir . '/vendor/a/provider',
            'aliases' => array(),
            'dev_requirement' => false,
        ),
        'a/provider2' => array(
            'pretty_version' => '1.2',
            'version' => '1.2.0.0',
            'reference' => 'distref-as-installed-from-dist',
            'type' => 'library',
            // @phpstan-ignore variable.undefined
            'install_path' => $dir . '/vendor/a/provider2',
            'aliases' => array(
              '1.4',
            ),
            'dev_requirement' => false,
        ),
        'b/replacer' => array(
            'pretty_version' => '2.2',
            'version' => '2.2.0.0',
            'reference' => null,
            'type' => 'library',
            // @phpstan-ignore variable.undefined
            'install_path' => $dir . '/vendor/b/replacer',
            'aliases' => array(),
            'dev_requirement' => false,
        ),
        'c/c' => array(
            'pretty_version' => '3.0',
            'version' => '3.0.0.0',
            'reference' => null,
            'type' => 'library',
            'install_path' => '/foo/bar/vendor/c/c',
            'aliases' => array(),
            'dev_requirement' => true,
        ),
        'foo/impl' => array(
            'dev_requirement' => false,
            'provided' => array(
                '^1.1',
                '1.2',
                '1.4',
                '2.0',
            ),
        ),
        'foo/impl2' => array(
            'dev_requirement' => false,
            'provided' => array(
                '2.0',
            ),
            'replaced' => array(
                '2.2',
            ),
        ),
        'foo/replaced' => array(
            'dev_requirement' => false,
            'replaced' => array(
                '^3.0',
            ),
        ),
        'meta/package' => array(
            'pretty_version' => '3.0',
            'version' => '3.0.0.0',
            'reference' => null,
            'type' => 'metapackage',
            'install_path' => null,
            'aliases' => array(),
            'dev_requirement' => false,
        )
    ),
);
