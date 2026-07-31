<?php declare(strict_types=1);

namespace Frosh\CmsImportExport\Service;

use Shopware\Core\Framework\Uuid\Uuid;

/**
 * CMS layouts reference media in two places: as plain foreign keys (`previewMediaId`, `backgroundMediaId`) and
 * buried inside the JSON config of a slot, whose shape depends on the element type
 * (`config.media.value`, `config.sliderItems.value[].mediaId`, and whatever a third party element invents).
 *
 * Rather than teaching this plugin every element type, both operations walk the payload and treat every
 * UUID-shaped string as a media candidate. The exporter confirms a candidate by looking it up in the media
 * repository, so unrelated ids (rule ids, mapped sources, ...) drop out on their own.
 *
 * @internal
 */
class MediaReferenceCollector
{
    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string> unique, UUID-shaped strings found anywhere in the payload
     */
    public function collectCandidates(array $payload): array
    {
        $found = [];
        $this->walk($payload, $found);

        return array_keys($found);
    }

    /**
     * Replaces every occurrence of the given ids. Ids mapped to `null` are cleared, which is how references to
     * media that could not be exported are neutralised.
     *
     * @param array<string, mixed> $payload
     * @param array<string, string|null> $map old media id => new media id
     *
     * @return array<string, mixed>
     */
    public function replace(array $payload, array $map): array
    {
        /** @var array<string, mixed> $replaced */
        $replaced = $this->replaceValue($payload, $map);

        return $replaced;
    }

    /**
     * @param array<string, bool> $found
     */
    private function walk(mixed $value, array &$found): void
    {
        if (\is_string($value)) {
            if (Uuid::isValid($value)) {
                $found[$value] = true;
            }

            return;
        }

        if (!\is_array($value)) {
            return;
        }

        foreach ($value as $item) {
            $this->walk($item, $found);
        }
    }

    /**
     * @param array<string, string|null> $map
     */
    private function replaceValue(mixed $value, array $map): mixed
    {
        if (\is_string($value)) {
            return \array_key_exists($value, $map) ? $map[$value] : $value;
        }

        if (!\is_array($value)) {
            return $value;
        }

        $result = [];
        foreach ($value as $key => $item) {
            $result[$key] = $this->replaceValue($item, $map);
        }

        return $result;
    }
}
