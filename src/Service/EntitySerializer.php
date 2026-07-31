<?php declare(strict_types=1);

namespace Frosh\CmsImportExport\Service;

use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Field;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Runtime;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\WriteProtected;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ReferenceVersionField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StorageAware;
use Shopware\Core\Framework\DataAbstractionLayer\Field\VersionField;

/**
 * Turns an entity into a plain write payload by walking its definition instead of a hard coded field list,
 * so fields added by future Shopware versions are exported without touching this plugin.
 *
 * @internal
 */
class EntitySerializer
{
    /**
     * Never carried over into an export: identities are regenerated on import, timestamps are set by the DAL
     * and `locked` would make the imported layout read only.
     */
    private const ALWAYS_SKIPPED = [
        'id',
        'createdAt',
        'updatedAt',
        'locked',
    ];

    /**
     * @param array<string> $skipProperties additional property names to leave out, e.g. the foreign key to the parent
     *
     * @return array<string, mixed>
     */
    public function serialize(EntityDefinition $definition, Entity $entity, array $skipProperties = []): array
    {
        $skip = [...self::ALWAYS_SKIPPED, ...$skipProperties];

        $data = [];
        foreach ($definition->getFields() as $field) {
            if (!$this->isExportable($field)) {
                continue;
            }

            $property = $field->getPropertyName();
            if (\in_array($property, $skip, true)) {
                continue;
            }

            $value = $entity->has($property) ? $entity->get($property) : null;
            if ($value === null) {
                continue;
            }

            $data[$property] = $this->normalize($value);
        }

        return $data;
    }

    private function isExportable(Field $field): bool
    {
        if (!$field instanceof StorageAware) {
            // Translated and association fields are handled explicitly by the caller.
            return false;
        }

        if ($field instanceof VersionField || $field instanceof ReferenceVersionField) {
            return false;
        }

        if ($field instanceof IdField && $field->getPropertyName() === 'id') {
            return false;
        }

        return !$field->is(Runtime::class) && !$field->is(WriteProtected::class);
    }

    private function normalize(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DATE_ATOM);
        }

        if (\is_array($value)) {
            return array_map(fn (mixed $item): mixed => $this->normalize($item), $value);
        }

        return $value;
    }
}
