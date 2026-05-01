<?php

declare(strict_types=1);

namespace IDM\Tests\Support;

use IDM\Domain\Reconciliation\DirectoryGateway;

/** In-memory {@see DirectoryGateway} for reconciler unit tests. */
final class FakeDirectoryGateway implements DirectoryGateway
{
    /** @var list<array<string, mixed>> */
    public array $people = [];

    /**
     * Whether CSV plaintext matches LDAP for each uid (false = external password change / mismatch).
     *
     * @var array<string, bool>
     */
    public array $passwordOkForUid = [];

    /** @var list<array{dn:string, httpUrl:string}> */
    public array $setLabeledUriCalls = [];

    /** @var list<string> */
    public array $quarantineCalls = [];

    /** @var list<string> */
    public array $passwordSyncCalls = [];

    public function searchPeople(): array
    {
        return $this->people;
    }

    public function verifyUserPassword(string $uid, string $plainPassword): bool
    {
        return $this->passwordOkForUid[$uid] ?? true;
    }

    public function setLabeledUri(string $dn, string $httpUrl): void
    {
        $this->setLabeledUriCalls[] = ['dn' => $dn, 'httpUrl' => $httpUrl];
    }

    public function quarantineUser(string $uid): void
    {
        $this->quarantineCalls[] = $uid;
    }

    public function applyUserPasswordFromPlain(string $uid, string $plainPassword): void
    {
        $this->passwordSyncCalls[] = $uid;
        $this->passwordOkForUid[$uid] = true;
    }
}
