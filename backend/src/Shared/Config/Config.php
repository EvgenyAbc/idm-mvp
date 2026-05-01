<?php

declare(strict_types=1);

namespace IDM\Shared\Config;

final class Config
{
    public static function get(string $key, ?string $default = null): ?string
    {
        $value = $_ENV[$key] ?? getenv($key);
        if ($value === false || $value === null || $value === '') {
            return $default;
        }

        return (string) $value;
    }

    public static function storagePath(string $suffix = ''): string
    {
        $base = realpath(__DIR__ . '/../../../../storage') ?: (__DIR__ . '/../../../../storage');
        return $suffix === '' ? $base : $base . '/' . ltrim($suffix, '/');
    }

    /**
     * SQLite DB path override.
     * - If SQLITE_DB_PATH is absolute, use it as is.
     * - If SQLITE_DB_PATH is relative, resolve under storage/.
     * - Else, default to storage/idm_alpha.sqlite.
     */
    public static function sqliteDbPath(): string
    {
        $configured = trim((string) (self::get('SQLITE_DB_PATH') ?? ''));
        if ($configured === '') {
            return self::storagePath('idm_alpha.sqlite');
        }

        if (str_starts_with($configured, '/')) {
            return $configured;
        }

        return self::storagePath($configured);
    }

    /**
     * LDAP server URI for all OpenLDAP CLI tools (ldapsearch, ldapmodify, ldapwhoami, …).
     * LDAP_URI overrides; else LDAP_CLIENT_URI (legacy); else localhost.
     * Must match the directory used by admin binds — reconcile password checks previously used
     * only LDAP_CLIENT_URI while ldapsearch relied on ldap.conf, which broke reconciliation
     * when those differed (e.g. Docker service hostname vs 127.0.0.1).
     */
    public static function ldapUri(): string
    {
        $uri = self::get('LDAP_URI') ?? self::get('LDAP_CLIENT_URI') ?? 'ldap://127.0.0.1:389';
        $uri = trim((string) $uri);

        return $uri !== '' ? $uri : 'ldap://127.0.0.1:389';
    }

}
