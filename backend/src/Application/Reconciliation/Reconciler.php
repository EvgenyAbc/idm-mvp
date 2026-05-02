<?php

declare(strict_types=1);

namespace IDM\Application\Reconciliation;

use IDM\Infrastructure\Persistence\AuditRepository;
use IDM\Shared\Config\Config;
use IDM\Domain\Reconciliation\DirectoryGateway;
use IDM\Domain\Provisioning\SourcePolicy;

final class Reconciler
{
    public function __construct(
        private DirectoryGateway $ldap,
        private SourcePolicy $source,
        private AuditRepository $audit
    ) {
    }

    public function run(string $runId, ?array $options = null): array
    {
        $rows = $this->source->rows();
        $csvRowsByUid = [];
        foreach ($rows as $row) {
            $csvRowsByUid[$row['user']] = $row;
        }

        $ldapUsers = $this->ldap->searchPeople();
        $ldapByUid = [];
        foreach ($ldapUsers as $person) {
            $uid = $person['uid'] ?? '';
            if ($uid !== '') {
                $ldapByUid[$uid] = $person;
            }
        }

        $driftDetected = 0;
        $driftRemediated = 0;
        $driftSkippedInvalidCsv = 0;
        $driftRemediateFailed = 0;
        $passwordsRemediated = 0;
        $passwordRemediateFailed = 0;

        $syncPasswords = $this->syncPasswordsEnabled($options);

        $verifyDelayUs = (int) (Config::get('RECONCILE_PASSWORD_VERIFY_DELAY_US', '0') ?: '0');
        if ($verifyDelayUs > 1_000_000) {
            $verifyDelayUs = 1_000_000;
        }
        $verifyIndex = 0;

        foreach ($csvRowsByUid as $uid => $row) {
            if (!isset($ldapByUid[$uid])) {
                continue;
            }
            if ($verifyIndex > 0 && $verifyDelayUs > 0) {
                usleep($verifyDelayUs);
            }
            $verifyIndex++;
            $csvPass = trim((string) ($row['password'] ?? ''));
            if ($csvPass === '') {
                continue;
            }
            if ($this->ldap->verifyUserPassword($uid, $csvPass)) {
                continue;
            }

            if ($syncPasswords) {
                try {
                    $this->ldap->applyUserPasswordFromPlain($uid, $csvPass);
                    if ($this->ldap->verifyUserPassword($uid, $csvPass)) {
                        $passwordsRemediated++;
                        $this->audit->log([
                            'run_id' => $runId,
                            'event_type' => 'reconciliation',
                            'username' => $uid,
                            'field_name' => 'userPassword',
                            'old_value' => null,
                            'new_value' => null,
                            'status' => 'remediated',
                            'reason' => 'userPassword replaced from source (admin modify + hash); bind check succeeded',
                        ]);
                    } else {
                        $driftDetected++;
                        $passwordRemediateFailed++;
                        $this->audit->log([
                            'run_id' => $runId,
                            'event_type' => 'reconciliation',
                            'username' => $uid,
                            'field_name' => 'userPassword',
                            'old_value' => null,
                            'new_value' => null,
                            'status' => 'denied',
                            'reason' => 'Password sync from source applied but bind check still failed',
                        ]);
                    }
                } catch (\Throwable $e) {
                    $driftDetected++;
                    $passwordRemediateFailed++;
                    $this->audit->log([
                        'run_id' => $runId,
                        'event_type' => 'reconciliation',
                        'username' => $uid,
                        'field_name' => 'userPassword',
                        'old_value' => null,
                        'new_value' => null,
                        'status' => 'denied',
                        'reason' => $e->getMessage(),
                    ]);
                }
            } else {
                $driftDetected++;
                $this->audit->log([
                    'run_id' => $runId,
                    'event_type' => 'reconciliation',
                    'username' => $uid,
                    'field_name' => 'userPassword',
                    'old_value' => null,
                    'new_value' => null,
                    'status' => 'drift_detected',
                    'reason' => 'LDAP password does not match source table (possible external change); not auto-remediated',
                ]);
            }
        }

        foreach ($csvRowsByUid as $uid => $row) {
            if (!isset($ldapByUid[$uid])) {
                continue;
            }
            $person = $ldapByUid[$uid];
            $csvVal = trim($row['httpUrl']);
            $ldapVal = trim((string) ($person['labeledURI'] ?? ''));
            if ($csvVal === $ldapVal) {
                continue;
            }

            $driftDetected++;
            $dn = trim((string) ($person['dn'] ?? ''));
            $baseEvent = [
                'run_id' => $runId,
                'event_type' => 'reconciliation',
                'username' => $uid,
                'field_name' => 'labeledURI',
                'old_value' => $ldapVal === '' ? null : $ldapVal,
                'new_value' => $csvVal === '' ? null : $csvVal,
            ];

            if (!$this->source->isAllowedUrl($csvVal)) {
                $driftSkippedInvalidCsv++;
                $this->audit->log($baseEvent + [
                    'status' => 'drift_detected',
                    'reason' => 'LDAP labeledURI differs from source; source httpUrl is not a valid URL — not remediated',
                ]);
                continue;
            }

            if ($dn === '') {
                $driftRemediateFailed++;
                $this->audit->log($baseEvent + [
                    'status' => 'denied',
                    'reason' => 'LDAP entry has no DN; cannot remediate labeledURI',
                ]);
                continue;
            }

            try {
                $this->ldap->setLabeledUri($dn, $csvVal);
                $driftRemediated++;
                $this->audit->log($baseEvent + [
                    'status' => 'remediated',
                    'reason' => 'Direct LDAP change reconciled from source (labeledURI)',
                ]);
            } catch (\Throwable $e) {
                $driftRemediateFailed++;
                $this->audit->log($baseEvent + [
                    'status' => 'denied',
                    'reason' => $e->getMessage(),
                ]);
            }
        }

        foreach ($csvRowsByUid as $uid => $row) {
            if (!isset($ldapByUid[$uid])) {
                continue;
            }
            $person = $ldapByUid[$uid];
            $csvVal = trim((string) ($row['mail'] ?? ''));
            $ldapVal = trim((string) ($person['mail'] ?? ''));
            if ($csvVal === $ldapVal) {
                continue;
            }

            $driftDetected++;
            $dn = trim((string) ($person['dn'] ?? ''));
            $baseEvent = [
                'run_id' => $runId,
                'event_type' => 'reconciliation',
                'username' => $uid,
                'field_name' => 'mail',
                'old_value' => $ldapVal === '' ? null : $ldapVal,
                'new_value' => $csvVal === '' ? null : $csvVal,
            ];

            if ($dn === '') {
                $driftRemediateFailed++;
                $this->audit->log($baseEvent + [
                    'status' => 'denied',
                    'reason' => 'LDAP entry has no DN; cannot remediate mail',
                ]);
                continue;
            }

            try {
                $this->ldap->setMail($dn, $csvVal);
                $driftRemediated++;
                $this->audit->log($baseEvent + [
                    'status' => 'remediated',
                    'reason' => 'Direct LDAP change reconciled from source (mail)',
                ]);
            } catch (\Throwable $e) {
                $driftRemediateFailed++;
                $this->audit->log($baseEvent + [
                    'status' => 'denied',
                    'reason' => $e->getMessage(),
                ]);
            }
        }

        foreach ($csvRowsByUid as $uid => $row) {
            if (!isset($ldapByUid[$uid])) {
                continue;
            }
            $person = $ldapByUid[$uid];
            $csvVal = trim((string) ($row['telephoneNumber'] ?? ''));
            $ldapVal = trim((string) ($person['telephoneNumber'] ?? ''));
            if ($csvVal === $ldapVal) {
                continue;
            }

            $driftDetected++;
            $dn = trim((string) ($person['dn'] ?? ''));
            $baseEvent = [
                'run_id' => $runId,
                'event_type' => 'reconciliation',
                'username' => $uid,
                'field_name' => 'telephoneNumber',
                'old_value' => $ldapVal === '' ? null : $ldapVal,
                'new_value' => $csvVal === '' ? null : $csvVal,
            ];

            if ($dn === '') {
                $driftRemediateFailed++;
                $this->audit->log($baseEvent + [
                    'status' => 'denied',
                    'reason' => 'LDAP entry has no DN; cannot remediate telephoneNumber',
                ]);
                continue;
            }

            try {
                $this->ldap->setTelephoneNumber($dn, $csvVal);
                $driftRemediated++;
                $this->audit->log($baseEvent + [
                    'status' => 'remediated',
                    'reason' => 'Direct LDAP change reconciled from source (telephoneNumber)',
                ]);
            } catch (\Throwable $e) {
                $driftRemediateFailed++;
                $this->audit->log($baseEvent + [
                    'status' => 'denied',
                    'reason' => $e->getMessage(),
                ]);
            }
        }

        $quarantined = 0;
        foreach ($ldapUsers as $person) {
            $uid = $person['uid'] ?? '';
            if ($uid === '' || isset($csvRowsByUid[$uid])) {
                continue;
            }
            $this->ldap->quarantineUser($uid);
            $this->audit->log([
                'run_id' => $runId,
                'event_type' => 'reconciliation',
                'username' => $uid,
                'status' => 'quarantined',
                'reason' => 'User exists in LDAP but missing from source table',
            ]);
            $quarantined++;
        }

        return [
            'quarantined' => $quarantined,
            'checked' => count($ldapUsers),
            'drift_detected' => $driftDetected,
            'drift_remediated' => $driftRemediated,
            'passwords_remediated' => $passwordsRemediated,
            'password_remediate_failed' => $passwordRemediateFailed,
            'drift_skipped_invalid_csv' => $driftSkippedInvalidCsv,
            'drift_remediate_failed' => $driftRemediateFailed,
        ];
    }

    /** True when JSON includes syncPasswords, or RECONCILE_SYNC_PASSWORDS env is enabled. */
    private function syncPasswordsEnabled(?array $options): bool
    {
        if ($options !== null && array_key_exists('syncPasswords', $options)) {
            return filter_var($options['syncPasswords'], FILTER_VALIDATE_BOOLEAN);
        }

        return in_array(
            strtolower((string) Config::get('RECONCILE_SYNC_PASSWORDS', '0')),
            ['1', 'true', 'yes'],
            true
        );
    }
}
