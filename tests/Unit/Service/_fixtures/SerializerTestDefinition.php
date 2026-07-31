<?php declare(strict_types=1);

namespace Frosh\CmsImportExport\Test\Unit\Service\_fixtures;

use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Runtime;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\WriteProtected;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IntField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\JsonField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\LockedField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\TranslatedField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\VersionField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

/**
 * Mirrors the field shapes a CMS entity can carry, so the serializer rules can be proven without booting the DAL.
 */
class SerializerTestDefinition extends EntityDefinition
{
    final public const ENTITY_NAME = 'frosh_serializer_test';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getEntityClass(): string
    {
        return SerializerTestEntity::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new PrimaryKey(), new Required()),
            new VersionField(),
            new StringField('name', 'name'),
            new IntField('position', 'position'),
            new LockedField(),
            new StringField('parent_id', 'parentId'),
            new JsonField('config', 'config'),
            (new JsonField('resolved_data', 'resolvedData'))->addFlags(new Runtime(), new WriteProtected()),
            new TranslatedField('description'),
        ]);
    }
}
