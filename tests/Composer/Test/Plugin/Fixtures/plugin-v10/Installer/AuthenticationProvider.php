<?php

namespace Installer;

use Composer\Plugin\Capability\AuthenticationProvider as AuthenticationProviderCapability;

class AuthenticationProvider implements AuthenticationProviderCapability
{
    public function __construct(array $args)
    {
        if (!$args['composer'] instanceof \Composer\Composer) {
            throw new \RuntimeException('Expected a "composer" key');
        }
        if (!$args['io'] instanceof \Composer\IO\IOInterface) {
            throw new \RuntimeException('Expected an "io" key');
        }
        if (!$args['plugin'] instanceof Plugin10) {
            throw new \RuntimeException('Expected a "plugin" key with my own plugin');
        }
    }

    public function getAuthentication(string $origin): ?array
    {
        if ($origin === 'fixture.example.org') {
            return array('username' => 'fixture-user', 'password' => 'fixture-pass');
        }

        return null;
    }
}
