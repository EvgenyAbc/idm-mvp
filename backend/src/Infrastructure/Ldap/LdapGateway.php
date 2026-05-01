<?php

declare(strict_types=1);

namespace IDM\Infrastructure\Ldap;

use IDM\Shared\Config\Config;
use IDM\Domain\Reconciliation\DirectoryGateway;
use RuntimeException;

final class LdapGateway implements DirectoryGateway
{
    private const EDITABLE_ATTRIBUTES = ['labeledURI', 'mail', 'telephoneNumber'];

    /** LDAP attribute names are case-insensitive; normalize to schema spelling for LDIF / UI. */
    private function canonicalEditableAttributeName(string $name): ?string
    {
        foreach (self::EDITABLE_ATTRIBUTES as $canon) {
            if (strcasecmp($name, $canon) === 0) {
                return $canon;
            }
        }

        return null;
    }

    /** Normalize known editable keys when parsing ldapsearch output. */
    private function normalizeParsedAttributeKey(string $name): string
    {
        return $this->canonicalEditableAttributeName($name) ?? $name;
    }
    private string $baseDn;
    private string $adminDn;
    private string $adminPassword;

    public function __construct()
    {
        $this->baseDn = Config::get('LDAP_BASE_DN', 'dc=example,dc=com');
        $this->adminDn = Config::get('LDAP_ADMIN_DN', 'cn=admin,dc=example,dc=com');
        $this->adminPassword = Config::get('LDAP_ADMIN_PASSWORD', '123');
    }

    public function health(): bool
    {
        try {
            $this->searchPeople();
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function searchPeople(): array
    {
        $dn = 'ou=People,' . $this->baseDn;
        $cmd = sprintf(
            "ldapsearch -x -H %s -LLL -D %s -w %s -b %s '(uid=*)' uid labeledURI uidNumber",
            escapeshellarg(Config::ldapUri()),
            escapeshellarg($this->adminDn),
            escapeshellarg($this->adminPassword),
            escapeshellarg($dn)
        );
        $out = $this->run($cmd);
        return $this->parsePeople($out);
    }

    public function browseTree(): array
    {
        return $this->browseTreeFromDn($this->baseDn);
    }

    public function browseTreeFromDn(string $baseDn): array
    {
        $targetDn = trim($baseDn);
        if ($targetDn === '') {
            $targetDn = $this->baseDn;
        }

        $cmd = sprintf(
            "ldapsearch -x -H %s -LLL -D %s -w %s -b %s '(objectClass=*)' '*'",
            escapeshellarg(Config::ldapUri()),
            escapeshellarg($this->adminDn),
            escapeshellarg($this->adminPassword),
            escapeshellarg($targetDn)
        );
        $out = $this->run($cmd);
        return $this->parseTree($out);
    }

    public function treeNodeByDn(string $dn): array
    {
        $targetDn = trim($dn);
        if ($targetDn === '') {
            throw new RuntimeException('Entry DN is required');
        }

        $entryCmd = sprintf(
            "ldapsearch -x -H %s -LLL -D %s -w %s -s base -b %s '(objectClass=*)' '*'",
            escapeshellarg(Config::ldapUri()),
            escapeshellarg($this->adminDn),
            escapeshellarg($this->adminPassword),
            escapeshellarg($targetDn)
        );
        $entryOut = $this->run($entryCmd);
        $items = $this->parseFlatEntries($entryOut);
        if ($items === []) {
            throw new RuntimeException('LDAP entry not found');
        }

        $node = $items[0];

        $childrenCmd = sprintf(
            "ldapsearch -x -H %s -LLL -D %s -w %s -s one -b %s '(objectClass=*)' dn",
            escapeshellarg(Config::ldapUri()),
            escapeshellarg($this->adminDn),
            escapeshellarg($this->adminPassword),
            escapeshellarg($targetDn)
        );
        $childrenOut = $this->run($childrenCmd);
        $childrenItems = $this->parseFlatEntries($childrenOut);
        $directChildren = array_values(array_filter($childrenItems, function (array $item) use ($targetDn): bool {
            return $this->normalizeDn((string) ($item['dn'] ?? '')) !== $this->normalizeDn($targetDn);
        }));
        usort($directChildren, function (array $a, array $b): int {
            return strcmp((string) ($a['rdn'] ?? ''), (string) ($b['rdn'] ?? ''));
        });
        $node['children'] = $directChildren;
        $node['childrenCount'] = count($directChildren);
        $node['hasChildren'] = $node['childrenCount'] > 0;

        return $node;
    }

    public function groupsForUser(string $username): array
    {
        if ($username === '') {
            return [];
        }

        $dn = 'ou=Groups,' . $this->baseDn;
        $cmd = sprintf(
            "ldapsearch -x -H %s -LLL -D %s -w %s -b %s %s cn",
            escapeshellarg(Config::ldapUri()),
            escapeshellarg($this->adminDn),
            escapeshellarg($this->adminPassword),
            escapeshellarg($dn),
            escapeshellarg(sprintf('(memberUid=%s)', $this->escapeFilterValue($username)))
        );
        $out = $this->run($cmd);
        $groups = [];
        foreach (preg_split("/\n\s*\n/", trim($out)) as $entry) {
            foreach (explode("\n", (string) $entry) as $line) {
                if (str_starts_with($line, 'cn: ')) {
                    $groups[] = trim(substr($line, 4));
                }
            }
        }
        sort($groups);
        return array_values(array_unique(array_filter($groups)));
    }

    public function searchEntries(string $query): array
    {
        $term = trim($query);
        if ($term === '') {
            return [];
        }

        $safe = $this->escapeFilterValue($term);
        $filter = sprintf('(|(uid=*%1$s*)(cn=*%1$s*)(mail=*%1$s*)(labeledURI=*%1$s*))', $safe);
        $cmd = sprintf(
            "ldapsearch -x -H %s -LLL -D %s -w %s -b %s %s '*'",
            escapeshellarg(Config::ldapUri()),
            escapeshellarg($this->adminDn),
            escapeshellarg($this->adminPassword),
            escapeshellarg($this->baseDn),
            escapeshellarg($filter)
        );
        $out = $this->run($cmd);
        return $this->parseFlatEntries($out);
    }

    public function updateEntryAttributes(string $dn, array $changes): array
    {
        $dn = trim($dn);
        if ($dn === '') {
            throw new RuntimeException('Entry DN is required');
        }

        $allowed = [];
        foreach ($changes as $attribute => $value) {
            $canonical = $this->canonicalEditableAttributeName((string) $attribute);
            if ($canonical === null) {
                continue;
            }
            $text = trim((string) $value);
            if ($text === '') {
                continue;
            }
            $allowed[$canonical] = $text;
        }

        if ($allowed === []) {
            throw new RuntimeException('No editable attributes provided');
        }

        $lines = [
            "dn: {$dn}",
            'changetype: modify',
        ];
        $first = true;
        foreach ($allowed as $attribute => $value) {
            if (!$first) {
                $lines[] = '-';
            }
            $lines[] = sprintf('replace: %s', $attribute);
            $lines[] = sprintf('%s: %s', $attribute, $value);
            $first = false;
        }
        $ldif = implode("\n", $lines) . "\n";
        $this->modifyWithLdif($ldif);

        return $allowed;
    }

    /**
     * Set or clear labeledURI only (no password or other attributes). Used by reconciliation drift remediation.
     */
    public function setLabeledUri(string $dn, string $httpUrl): void
    {
        $dn = trim($dn);
        if ($dn === '') {
            throw new RuntimeException('Entry DN is required');
        }

        $text = trim($httpUrl);
        if ($text === '') {
            $ldif = "dn: {$dn}\nchangetype: modify\ndelete: labeledURI\n";
        } else {
            $ldif = "dn: {$dn}\nchangetype: modify\nreplace: labeledURI\nlabeledURI: {$text}\n";
        }
        $this->modifyWithLdif($ldif);
    }

    public function exportEntries(string $query): array
    {
        $term = trim($query);
        if ($term === '') {
            $tree = $this->browseTree();
            return $this->flattenTreeEntries($tree);
        }
        return $this->searchEntries($term);
    }

    public function upsertUser(string $uid, string $password, string $httpUrl): void
    {
        $existing = $this->findUser($uid);
        if ($existing === null) {
            $this->addUser($uid, $password, $httpUrl);
            return;
        }
        $this->modifyUser($uid, $password, $httpUrl, $existing);
    }

    public function applyApprovedChange(string $username, string $field, string $value): void
    {
        if ($field === 'labeledURI') {
            $existing = $this->findUser($username);
            if ($existing === null) {
                throw new RuntimeException('User not found for labeledURI approval');
            }
            $dn = trim((string) ($existing['dn'] ?? ''));
            if ($dn === '') {
                $dn = sprintf('uid=%s,ou=People,%s', $username, $this->baseDn);
            }
            $this->setLabeledUri($dn, $value);

            return;
        }

        if ($field !== 'userPassword') {
            return;
        }
        $hash = $this->passwordHash($value);
        $existing = $this->findUser($username);
        $dn = $existing['dn'] ?? sprintf('uid=%s,ou=People,%s', $username, $this->baseDn);
        $ldif = "dn: {$dn}\nchangetype: modify\nreplace: userPassword\nuserPassword: {$hash}\n";
        $this->modifyWithLdif($ldif);
    }

    /** Labeled URI for a People uid, or null if unset or user missing. */
    public function labeledUriForUid(string $uid): ?string
    {
        $person = $this->findUser(trim($uid));
        if ($person === null) {
            return null;
        }
        $v = $person['labeledURI'] ?? null;
        if ($v === null || $v === '') {
            return null;
        }

        return (string) $v;
    }

    /**
     * LDAP simple bind check for People uid + password.
     * Used by reconciliation to compare live credentials to CSV without exposing hashes in audit logs.
     *
     * Order: PHP {@see ldap_bind} when the ldap extension is loaded (avoids spawning hundreds of
     * ldapwhoami processes and common false drift under load); else ldapwhoami with -y passwdfile.
     * Retries are for transient directory/network errors only.
     */
    public function verifyUserPassword(string $uid, string $plainPassword): bool
    {
        $uid = trim($uid);
        if ($uid === '') {
            return false;
        }

        $dn = sprintf('uid=%s,ou=People,%s', $uid, $this->baseDn);

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            if ($this->ldapSimpleBindVerify($dn, $plainPassword)) {
                return true;
            }
            if ($attempt < 3) {
                usleep(100_000);
            }
        }

        return false;
    }

    /**
     * One attempt: prefer native ldap_bind, then ldapwhoami CLI fallback.
     */
    private function ldapSimpleBindVerify(string $userDn, string $plainPassword): bool
    {
        if (extension_loaded('ldap') && $this->ldapExtensionBind($userDn, $plainPassword)) {
            return true;
        }

        return $this->ldapWhoamiBindVerify($userDn, $plainPassword);
    }

    private function ldapExtensionBind(string $userDn, string $plainPassword): bool
    {
        $uri = Config::ldapUri();
        $conn = @ldap_connect($uri);
        if ($conn === false) {
            return false;
        }

        ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);
        $timeout = (int) (Config::get('LDAP_NETWORK_TIMEOUT_SEC', '5') ?: '5');
        if ($timeout > 0) {
            @ldap_set_option($conn, LDAP_OPT_NETWORK_TIMEOUT, $timeout);
        }

        $ok = @ldap_bind($conn, $userDn, $plainPassword);
        ldap_close($conn);

        return $ok === true;
    }

    /** Single ldapwhoami exec; password via -y file (no shell quoting issues). */
    private function ldapWhoamiBindVerify(string $userDn, string $plainPassword): bool
    {
        $uri = Config::ldapUri();
        $pwFile = tempnam(sys_get_temp_dir(), 'idm_pwchk_');
        if ($pwFile === false) {
            return false;
        }

        try {
            file_put_contents($pwFile, $plainPassword);
            chmod($pwFile, 0600);

            $cmd = sprintf(
                'ldapwhoami -x -H %s -D %s -y %s',
                escapeshellarg(trim((string) $uri)),
                escapeshellarg($userDn),
                escapeshellarg($pwFile)
            );
            $output = [];
            $code = 0;
            exec($cmd . ' 2>&1', $output, $code);

            return $code === 0;
        } finally {
            @unlink($pwFile);
        }
    }

    public function ensureQuarantineGroup(): void
    {
        $groupDn = 'cn=quarantine,ou=Groups,' . $this->baseDn;
        $cmd = sprintf(
            "ldapsearch -x -H %s -LLL -D %s -w %s -b %s '(objectClass=posixGroup)' cn",
            escapeshellarg(Config::ldapUri()),
            escapeshellarg($this->adminDn),
            escapeshellarg($this->adminPassword),
            escapeshellarg($groupDn)
        );
        try {
            $this->run($cmd);
        } catch (\Throwable) {
            $ldif = "dn: {$groupDn}\nobjectClass: top\nobjectClass: posixGroup\ncn: quarantine\ngidNumber: 15000\n";
            $this->addWithLdif($ldif);
        }
    }

    public function applyUserPasswordFromPlain(string $uid, string $plainPassword): void
    {
        $this->applyApprovedChange(trim($uid), 'userPassword', $plainPassword);
    }

    public function quarantineUser(string $uid): void
    {
        $this->ensureQuarantineGroup();
        $dn = 'cn=quarantine,ou=Groups,' . $this->baseDn;
        $ldif = "dn: {$dn}\nchangetype: modify\nadd: memberUid\nmemberUid: {$uid}\n";
        try {
            $this->modifyWithLdif($ldif);
        } catch (\Throwable) {
            // User may already be in quarantine; ignore duplicate memberUid errors.
        }
    }

    /** LDAP DN for a uid under ou=People, or null if not found. */
    public function dnForUid(string $uid): ?string
    {
        $person = $this->findUser(trim($uid));
        if ($person === null) {
            return null;
        }
        $dn = trim((string) ($person['dn'] ?? ''));

        return $dn !== '' ? $dn : null;
    }

    private function findUser(string $uid): ?array
    {
        foreach ($this->searchPeople() as $person) {
            if (($person['uid'] ?? '') === $uid) {
                return $person;
            }
        }
        return null;
    }

    private function addUser(string $uid, string $password, string $httpUrl): void
    {
        $hash = $this->passwordHash($password);
        $dn = sprintf('uid=%s,ou=People,%s', $uid, $this->baseDn);
        $uidNumber = $this->nextUidNumber();
        $ldif = "dn: {$dn}\nobjectClass: inetOrgPerson\nobjectClass: posixAccount\nobjectClass: shadowAccount\nuid: {$uid}\nou: People\ncn: {$uid}\nsn: {$uid}\nuidNumber: {$uidNumber}\ngidNumber: 5000\nhomeDirectory: /home/{$uid}\nloginShell: /bin/bash\nuserPassword: {$hash}\nlabeledURI: {$httpUrl}\n";
        $this->addWithLdif($ldif);
    }

    /** Next free POSIX uidNumber under ou=People (avoids duplicate 20000 on bulk import). */
    private function nextUidNumber(): int
    {
        $max = 19999;
        foreach ($this->searchPeople() as $person) {
            $n = (int) ($person['uidNumber'] ?? 0);
            if ($n > $max) {
                $max = $n;
            }
        }

        return $max + 1;
    }

    private function modifyUser(string $uid, string $password, string $httpUrl, array $existing): void
    {
        $hash = $this->passwordHash($password);
        $dn = $existing['dn'] ?? sprintf('uid=%s,ou=People,%s', $uid, $this->baseDn);
        $ldif = "dn: {$dn}\nchangetype: modify\nreplace: labeledURI\nlabeledURI: {$httpUrl}\n-\nreplace: userPassword\nuserPassword: {$hash}\n";
        $this->modifyWithLdif($ldif);
    }

    private function addWithLdif(string $ldif): void
    {
        $file = tempnam(sys_get_temp_dir(), 'idm_add_');
        if ($file === false) {
            throw new RuntimeException('Cannot create temporary file');
        }
        file_put_contents($file, $ldif);
        $cmd = sprintf(
            'ldapadd -x -H %s -D %s -w %s -f %s',
            escapeshellarg(Config::ldapUri()),
            escapeshellarg($this->adminDn),
            escapeshellarg($this->adminPassword),
            escapeshellarg($file)
        );
        try {
            $this->run($cmd);
        } finally {
            @unlink($file);
        }
    }

    private function modifyWithLdif(string $ldif): void
    {
        $file = tempnam(sys_get_temp_dir(), 'idm_mod_');
        if ($file === false) {
            throw new RuntimeException('Cannot create temporary file');
        }
        file_put_contents($file, $ldif);
        $cmd = sprintf(
            'ldapmodify -x -H %s -D %s -w %s -f %s',
            escapeshellarg(Config::ldapUri()),
            escapeshellarg($this->adminDn),
            escapeshellarg($this->adminPassword),
            escapeshellarg($file)
        );
        try {
            $this->run($cmd);
        } finally {
            @unlink($file);
        }
    }

    private function run(string $cmd): string
    {
        $output = [];
        $code = 0;
        exec($cmd . ' 2>&1', $output, $code);
        $text = implode("\n", $output);
        if ($code !== 0) {
            throw new RuntimeException($text === '' ? 'LDAP command failed' : $text);
        }
        return $text;
    }

    private function passwordHash(string $password): string
    {
        $cmd = 'slappasswd -s ' . escapeshellarg($password);
        return trim($this->run($cmd));
    }

    private function parsePeople(string $raw): array
    {
        $entries = preg_split("/\n\s*\n/", trim($raw));
        $people = [];
        foreach ($entries as $entry) {
            if ($entry === '') {
                continue;
            }
            $person = [];
            foreach (explode("\n", $entry) as $line) {
                if (str_starts_with($line, 'dn: ')) {
                    $person['dn'] = substr($line, 4);
                } elseif (str_starts_with($line, 'uid: ')) {
                    $person['uid'] = substr($line, 5);
                } elseif (str_starts_with($line, 'labeledURI: ')) {
                    $person['labeledURI'] = substr($line, 12);
                } elseif (str_starts_with($line, 'uidNumber: ')) {
                    $person['uidNumber'] = substr($line, 10);
                }
            }
            if (!empty($person)) {
                $people[] = $person;
            }
        }
        return $people;
    }

    private function parseTree(string $raw): array
    {
        $entries = preg_split("/\n\s*\n/", trim($raw));
        $nodes = [];
        $dnIndex = [];
        foreach ($entries as $entry) {
            if ($entry === '') {
                continue;
            }
            $lines = explode("\n", $entry);
            $dn = '';
            $attributes = [];
            $lastAttribute = null;
            foreach ($lines as $line) {
                if (str_starts_with($line, ' ') && $lastAttribute !== null) {
                    $currentValues = $attributes[$lastAttribute];
                    $lastIdx = count($currentValues) - 1;
                    if ($lastIdx >= 0) {
                        $attributes[$lastAttribute][$lastIdx] .= trim($line);
                    }
                    continue;
                }
                if (str_starts_with($line, 'dn: ')) {
                    $dn = trim(substr($line, 4));
                    $lastAttribute = null;
                    continue;
                }
                if (str_contains($line, ':: ')) {
                    $parts = explode(':: ', $line, 2);
                } else {
                    $parts = explode(': ', $line, 2);
                }
                if (count($parts) !== 2 || $parts[0] === '') {
                    continue;
                }
                $name = trim($parts[0]);
                $value = trim($parts[1]);
                if ($name === '') {
                    continue;
                }
                $attrKey = $this->normalizeParsedAttributeKey($name);
                if (!isset($attributes[$attrKey])) {
                    $attributes[$attrKey] = [];
                }
                $attributes[$attrKey][] = $value;
                $lastAttribute = $attrKey;
            }
            if ($dn === '') {
                continue;
            }
            $node = [
                'dn' => $dn,
                'rdn' => explode(',', $dn, 2)[0],
                'attributes' => $attributes,
                'children' => [],
            ];
            $key = $this->normalizeDn($dn);
            $nodes[$key] = $node;
            $dnIndex[$key] = $dn;
        }

        $roots = [];
        foreach ($dnIndex as $key => $originalDn) {
            $parentDn = $this->parentDn($originalDn);
            $parentKey = $parentDn !== null ? $this->normalizeDn($parentDn) : null;
            if ($parentKey !== null && isset($nodes[$parentKey])) {
                $nodes[$parentKey]['children'][] = $nodes[$key];
                continue;
            }
            $roots[] = $nodes[$key];
        }

        return $this->sortNodes($roots);
    }

    private function parentDn(string $dn): ?string
    {
        $parts = explode(',', $dn, 2);
        if (count($parts) < 2) {
            return null;
        }
        return trim($parts[1]);
    }

    private function sortNodes(array $nodes): array
    {
        usort($nodes, function (array $a, array $b): int {
            return strcmp((string) ($a['rdn'] ?? ''), (string) ($b['rdn'] ?? ''));
        });
        foreach ($nodes as &$node) {
            if (!empty($node['children'])) {
                $node['children'] = $this->sortNodes($node['children']);
            }
        }
        unset($node);
        return $nodes;
    }

    private function normalizeDn(string $dn): string
    {
        return strtolower(trim($dn));
    }

    private function parseFlatEntries(string $raw): array
    {
        $entries = preg_split("/\n\s*\n/", trim($raw));
        $items = [];
        foreach ($entries as $entry) {
            if ($entry === '') {
                continue;
            }
            $lines = explode("\n", $entry);
            $dn = '';
            $attributes = [];
            foreach ($lines as $line) {
                if (str_starts_with($line, 'dn: ')) {
                    $dn = trim(substr($line, 4));
                    continue;
                }
                if (str_contains($line, ': ')) {
                    [$name, $value] = explode(': ', $line, 2);
                    $name = trim($name);
                    if ($name === '') {
                        continue;
                    }
                    $attrKey = $this->normalizeParsedAttributeKey($name);
                    if (!isset($attributes[$attrKey])) {
                        $attributes[$attrKey] = [];
                    }
                    $attributes[$attrKey][] = trim($value);
                }
            }
            if ($dn === '') {
                continue;
            }
            $items[] = [
                'dn' => $dn,
                'rdn' => explode(',', $dn, 2)[0],
                'attributes' => $attributes,
            ];
        }
        return $items;
    }

    private function escapeFilterValue(string $value): string
    {
        $replace = [
            '\\' => '\\5c',
            '*' => '\\2a',
            '(' => '\\28',
            ')' => '\\29',
            "\0" => '\\00',
        ];
        return strtr($value, $replace);
    }

    private function flattenTreeEntries(array $nodes, array $items = []): array
    {
        foreach ($nodes as $node) {
            $items[] = [
                'dn' => $node['dn'] ?? '',
                'rdn' => $node['rdn'] ?? '',
                'attributes' => $node['attributes'] ?? [],
            ];
            $children = $node['children'] ?? [];
            if (is_array($children) && $children !== []) {
                $items = $this->flattenTreeEntries($children, $items);
            }
        }
        return $items;
    }
}
