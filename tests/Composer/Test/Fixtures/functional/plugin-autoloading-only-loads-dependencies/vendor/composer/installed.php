<?php return array(
    'root' => array(
        'pretty_version' => '1.2.3',
        'version' => '1.2.3.0',
        'type' => 'library',
        'install_path' => __DIR__ . '/../../',
        'aliases' => array(),
        'reference' => NULL,
        'name' => 'root/pkg',
        'dev' => true,
    ),
    'versions' => array(
        'evil/pkg' => array(
            'pretty_version' => '1.0.0',
            'version' => '1.0.0.0',
            'type' => 'library',
            'install_path' => __DIR__ . '/../evil/pkg',
            'aliases' => array(),
            'reference' => 'a95061d7a7e3cf4466381fd1abc504279c95b231',
            'dev_requirement' => false,
        ),
        'root/pkg' => array(
            'pretty_version' => '1.2.3',
            'version' => '1.2.3.0',
            'type' => 'library',
            'install_path' => __DIR__ . '/../../',
            'aliases' => array(),
            'reference' => NULL,
            'dev_requirement' => false,
        ),
    ),
);
