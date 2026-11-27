<?php

declare(strict_types=1);

namespace App\Identity\Domain\Model;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'user_public_keys')]
class PublicKey
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64)]
    private string $algorithm;

    #[ORM\Column(type: 'text')]
    private string $keyData;

    #[ORM\Column(length: 128, unique: true)]
    private string $fingerprint;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $comment;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'publicKeys')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $user,
        SshKey $key,
        #[ORM\Column]
        private \DateTimeImmutable $createdAt,
    ) {
        $this->algorithm = $key->algorithm;
        $this->keyData = $key->data;
        $this->fingerprint = $key->fingerprint;
        $this->comment = $key->comment;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function user(): User
    {
        return $this->user;
    }

    public function fingerprint(): string
    {
        return $this->fingerprint;
    }

    public function sshKey(): SshKey
    {
        return SshKey::parse($this->authorizedKey());
    }

    /** OpenSSH `authorized_keys` form: `<algorithm> <base64> [comment]`. */
    public function authorizedKey(): string
    {
        return rtrim($this->algorithm.' '.$this->keyData.' '.($this->comment ?? ''));
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
