<?php declare(strict_types=1);

namespace Frosh\CmsImportExport\Test\Unit\Service\_fixtures;

use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

class SerializerTestEntity extends Entity
{
    use EntityIdTrait;

    public ?string $versionId = null;

    public ?string $name = null;

    public ?int $position = null;

    public ?bool $locked = null;

    public ?string $parentId = null;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $config = null;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $resolvedData = null;

    public ?\DateTimeInterface $createdAt = null;
}
