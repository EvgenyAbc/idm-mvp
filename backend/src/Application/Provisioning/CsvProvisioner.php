<?php

declare(strict_types=1);

namespace IDM\Application\Provisioning;

use IDM\Infrastructure\Persistence\ApprovalRepository;
use IDM\Infrastructure\Persistence\AuditRepository;
use IDM\Infrastructure\Ldap\LdapGateway;
use IDM\Infrastructure\Persistence\SourceUserRepository;
use IDM\Domain\Provisioning\SourcePolicy;

final class CsvProvisioner implements SourcePolicy
{
    public function __construct(
        private LdapGateway $ldap,
        private AuditRepository $audit,
        private ApprovalRepository $approvals,
        private SourceUserRepository $sourceUsers
    ) {
    }

    public function run(string $runId): array
    {
        $rows = $this->rows();
        $existing = [];
        foreach ($this->ldap->searchPeople() as $item) {
            $existing[$item['uid']] = $item;
        }

        $result = ['processed' => 0, 'allowed' => 0, 'pending_approval' => 0, 'errors' => 0];
        foreach ($rows as $row) {
            $result['processed']++;
            $uid = $row['user'];
            $oldUrl = $existing[$uid]['labeledURI'] ?? null;
            $hasExistingLdapEntry = isset($existing[$uid]);
            $passwordDiffers = true;
            if ($hasExistingLdapEntry) {
                // In Alpha, LDAP password changes are sensitive and tracked via approvals.
                // Only queue a password approval when the *live* LDAP credentials do not match
                // the CSV plaintext password.
                try {
                    $passwordDiffers = !$this->ldap->verifyUserPassword($uid, $row['password']);
                } catch (\Throwable) {
                    // If LDAP verification fails (transient directory issues), be conservative
                    // and assume drift so we don't miss a required password update approval.
                    $passwordDiffers = true;
                }
            }

            if ($passwordDiffers) {
                // Password changes are sensitive and require approval in Alpha.
                $this->approvals->create($uid, 'userPassword', null, $row['password'], 'Password updates require approval');
                $this->audit->log([
                    'run_id' => $runId,
                    'event_type' => 'provisioning',
                    'username' => $uid,
                    'field_name' => 'userPassword',
                    'old_value' => null,
                    'new_value' => '[REDACTED]',
                    'status' => 'pending_approval',
                    'reason' => 'Password updates require approval (LDAP drift detected vs source)',
                ]);
                $result['pending_approval']++;
            }

            if (!$this->isAllowedUrl($row['httpUrl'])) {
                $this->approvals->create($uid, 'labeledURI', $oldUrl, $row['httpUrl'], 'Denied URL policy');
                $this->audit->log([
                    'run_id' => $runId,
                    'event_type' => 'provisioning',
                    'username' => $uid,
                    'field_name' => 'labeledURI',
                    'old_value' => $oldUrl,
                    'new_value' => $row['httpUrl'],
                    'status' => 'pending_approval',
                    'reason' => 'Denied URL policy',
                ]);
                $result['pending_approval']++;

                // URL policy denial should not block password sync for existing LDAP users.
                // Keep prior labeledURI unchanged and update only the password hash (only if it differs).
                if ($hasExistingLdapEntry && $passwordDiffers) {
                    try {
                        $this->ldap->applyUserPasswordFromPlain($uid, $row['password']);
                    } catch (\Throwable $e) {
                        $this->audit->log([
                            'run_id' => $runId,
                            'event_type' => 'provisioning',
                            'username' => $uid,
                            'field_name' => 'userPassword',
                            'new_value' => '[REDACTED]',
                            'status' => 'denied',
                            'reason' => $e->getMessage(),
                        ]);
                        $result['errors']++;
                    }
                }
                continue;
            }

            try {
                $this->ldap->upsertUser($uid, $row['password'], $row['httpUrl']);
                $this->audit->log([
                    'run_id' => $runId,
                    'event_type' => 'provisioning',
                    'username' => $uid,
                    'field_name' => 'labeledURI',
                    'old_value' => $oldUrl,
                    'new_value' => $row['httpUrl'],
                    'status' => 'allowed',
                ]);
                $result['allowed']++;
            } catch (\Throwable $e) {
                $this->audit->log([
                    'run_id' => $runId,
                    'event_type' => 'provisioning',
                    'username' => $uid,
                    'field_name' => 'labeledURI',
                    'new_value' => $row['httpUrl'],
                    'status' => 'denied',
                    'reason' => $e->getMessage(),
                ]);
                $result['errors']++;
            }
        }

        return $result;
    }

    public function rows(): array
    {
        return $this->sourceUsers->all();
    }

    /**
     * @return list<array{user:string,password:string,httpUrl:string}>
     */
    public function parseCsvFile(string $csvPath): array
    {
        if (!is_readable($csvPath)) {
            throw new \RuntimeException('CSV file is not readable: ' . $csvPath);
        }

        $rows = [];
        $fp = fopen($csvPath, 'rb');
        if ($fp === false) {
            throw new \RuntimeException('Cannot open CSV file');
        }
        $header = fgetcsv($fp);
        if ($header === false) {
            fclose($fp);
            return [];
        }
        while (($line = fgetcsv($fp)) !== false) {
            $row = array_combine($header, $line);
            if (!$row || empty($row['user'])) {
                continue;
            }
            $rows[] = [
                'user' => trim((string) $row['user']),
                'password' => (string) ($row['password'] ?? ''),
                'httpUrl' => trim((string) ($row['httpUrl'] ?? '')),
            ];
        }
        fclose($fp);
        return $rows;
    }

    /**
     * Imports CSV rows into SQLite source_users and returns imported rows.
     *
     * @return list<array{user:string,password:string,httpUrl:string}>
     */
    public function importCsvFile(string $csvPath): array
    {
        return $this->sourceUsers->replaceAll($this->parseCsvFile($csvPath));
    }

    public function isAllowedUrl(string $url): bool
    {
        return (bool) filter_var($url, FILTER_VALIDATE_URL);
    }
}
