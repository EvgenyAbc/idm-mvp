<?php

declare(strict_types=1);

namespace IDM\Domain\Reconciliation;

/** LDAP operations required by {@see Reconciler}. */
interface DirectoryGateway
{
    /**
     * List People entries (uid, dn, labeledURI, mail, telephoneNumber, uidNumber as available).
     *
     * @return list<array<string, mixed>>
     */
    public function searchPeople(): array;

    /** True if simple bind succeeds with given plaintext password. */
    public function verifyUserPassword(string $uid, string $plainPassword): bool;

    public function setLabeledUri(string $dn, string $httpUrl): void;

    public function setMail(string $dn, string $mail): void;

    public function setTelephoneNumber(string $dn, string $telephoneNumber): void;

    public function quarantineUser(string $uid): void;

    /** Replace LDAP userPassword using CSV plaintext (admin modify + hash). Used when syncing passwords from CSV. */
    public function applyUserPasswordFromPlain(string $uid, string $plainPassword): void;
}
