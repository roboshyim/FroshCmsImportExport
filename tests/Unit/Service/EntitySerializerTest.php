<?php declare(strict_types=1);

namespace Frosh\CmsImportExport\Test\Unit\Service;

use Frosh\CmsImportExport\Service\EntitySerializer;
use Frosh\CmsImportExport\Test\Unit\Service\_fixtures\SerializerTestDefinition;
use Frosh\CmsImportExport\Test\Unit\Service\_fixtures\SerializerTestEntity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Write\EntityWriteGatewayInterface;
use Shopware\Core\Test\Stub\DataAbstractionLayer\StaticDefinitionInstanceRegistry;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[CoversClass(EntitySerializer::class)]
class EntitySerializerTest extends TestCase
{
    private EntitySerializer $serializer;

    private EntityDefinition $definition;

    protected function setUp(): void
    {
        $this->serializer = new EntitySerializer();

        $registry = new StaticDefinitionInstanceRegistry(
            [SerializerTestDefinition::class],
            static::createStub(ValidatorInterface::class),
            static::createStub(EntityWriteGatewayInterface::class)
        );

        $this->definition = $registry->getByEntityName(SerializerTestDefinition::ENTITY_NAME);
    }

    public function testExportsStoredFieldsAndDropsIdentityAndTimestamps(): void
    {
        $entity = new SerializerTestEntity();
        $entity->setUniqueIdentifier('test');
        $entity->setId('0102030405060708090a0b0c0d0e0f10');
        $entity->versionId = '1112131415161718191a1b1c1d1e1f20';
        $entity->name = 'Hero block';
        $entity->position = 3;
        $entity->createdAt = new \DateTimeImmutable('2026-01-01 12:00:00');

        $data = $this->serializer->serialize($this->definition, $entity);

        static::assertSame(['name' => 'Hero block', 'position' => 3], $data);
    }

    public function testSkipsLockedSoImportedLayoutsStayEditable(): void
    {
        $entity = new SerializerTestEntity();
        $entity->setUniqueIdentifier('test');
        $entity->locked = true;
        $entity->name = 'Locked default layout';

        $data = $this->serializer->serialize($this->definition, $entity);

        static::assertArrayNotHasKey('locked', $data);
    }

    public function testSkipsRuntimeAndWriteProtectedFields(): void
    {
        $entity = new SerializerTestEntity();
        $entity->setUniqueIdentifier('test');
        $entity->resolvedData = ['resolved' => 'by the storefront'];

        $data = $this->serializer->serialize($this->definition, $entity);

        static::assertArrayNotHasKey('resolvedData', $data);
    }

    public function testSkipsExplicitlyExcludedProperties(): void
    {
        $entity = new SerializerTestEntity();
        $entity->setUniqueIdentifier('test');
        $entity->parentId = '0102030405060708090a0b0c0d0e0f10';
        $entity->name = 'Slot';

        $data = $this->serializer->serialize($this->definition, $entity, ['parentId']);

        static::assertSame(['name' => 'Slot'], $data);
    }

    public function testKeepsNestedJsonConfigAsIs(): void
    {
        $config = [
            'media' => ['source' => 'static', 'value' => '0102030405060708090a0b0c0d0e0f10'],
            'minHeight' => ['source' => 'static', 'value' => '320px'],
        ];

        $entity = new SerializerTestEntity();
        $entity->setUniqueIdentifier('test');
        $entity->config = $config;

        $data = $this->serializer->serialize($this->definition, $entity);

        static::assertSame($config, $data['config']);
    }

    public function testFormatsDatesInsideJsonAsIso8601(): void
    {
        $entity = new SerializerTestEntity();
        $entity->setUniqueIdentifier('test');
        $entity->config = ['publishedAt' => new \DateTimeImmutable('2026-01-01 12:00:00+00:00')];

        $data = $this->serializer->serialize($this->definition, $entity);

        static::assertSame(['publishedAt' => '2026-01-01T12:00:00+00:00'], $data['config']);
    }
}
