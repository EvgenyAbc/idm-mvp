<?php

declare(strict_types=1);

namespace IDM\Infrastructure\Ldap;

use IDM\Shared\Config\Config;

final class AuthService
{
    public function login(string $username, string $password): bool
    {
        $baseDn = Config::get('LDAP_BASE_DN', 'dc=example,dc=com');
        $userDn = sprintf('uid=%s,ou=People,%s', $username, $baseDn);
        $cmd = sprintf(
            'ldapwhoami -x -H %s -D %s -w %s',
            escapeshellarg(Config::ldapUri()),
            escapeshellarg($userDn),
            escapeshellarg($password)
        );
        $output = [];
        $code = 0;
        exec($cmd . ' 2>&1', $output, $code);
        return $code === 0;
    }
}
