<?php return array(
    'root' => array(
        'name' => '__root__',
        'pretty_version' => 'dev-master',
        'version' => 'dev-master',
        'aliases' => array(
            '1.10.x-dev',
        ),
        'reference' => 'sourceref-by-default',
        'is_dev' => true,
        'is_abandoned' => false,
    ),
    'versions' => array(
        '__root__' => array(
            'name' => '__root__',
            'pretty_version' => 'dev-master',
            'version' => 'dev-master',
            'aliases' => array(
                '1.10.x-dev',
            ),
            'reference' => 'sourceref-by-default',
            'is_dev' => true,
            'is_abandoned' => false,
        ),
        'a/provider' => array(
            'name' => 'a/provider',
            'pretty_version' => '1.1',
            'version' => '1.1.0.0',
            'aliases' => array(),
            'reference' => 'distref-as-no-source',
            'is_dev' => false,
            'is_abandoned' => false,
        ),
        'a/provider2' => array(
            'name' => 'a/provider2',
            'pretty_version' => '1.2',
            'version' => '1.2.0.0',
            'aliases' => array(
              '1.4',
            ),
            'reference' => 'distref-as-installed-from-dist',
            'is_dev' => false,
            'is_abandoned' => false,
        ),
        'b/replacer' => array(
            'name' => 'b/replacer',
            'pretty_version' => '2.2',
            'version' => '2.2.0.0',
            'aliases' => array(),
            'reference' => NULL,
            'is_dev' => false,
            'is_abandoned' => false,
        ),
        'c/c' => array(
            'name' => 'c/c',
            'pretty_version' => '3.0',
            'version' => '3.0.0.0',
            'aliases' => array(),
            'reference' => NULL,
            'is_dev' => false,
            'is_abandoned' => false,
        ),
        'foo/impl' => array(
            'provided' => array(
                '^1.1',
                '1.2',
                '1.4',
                '2.0',
            )
        ),
        'foo/impl2' => array(
            'provided' => array(
                '2.0',
            ),
            'replaced' => array(
                '2.2',
            )
        ),
        'foo/replaced' => array(
            'replaced' => array(
                '^3.0',
            )
        ),
    ),
);
