<?php declare(strict_types=1);

namespace Frosh\CmsImportExport\Service;

use Frosh\CmsImportExport\Archive\CmsPageArchive;
use Frosh\CmsImportExport\Exception\CmsImportExportException;
use Frosh\CmsImportExport\Struct\ExportResult;
use Psr\Clock\ClockInterface;
use Shopware\Core\Content\Cms\Aggregate\CmsBlock\CmsBlockEntity;
use Shopware\Core\Content\Cms\Aggregate\CmsSection\CmsSectionEntity;
use Shopware\Core\Content\Cms\Aggregate\CmsSlot\CmsSlotEntity;
use Shopware\Core\Content\Cms\CmsPageCollection;
use Shopware\Core\Content\Cms\CmsPageEntity;
use Shopware\Core\Content\Media\MediaCollection;
use Shopware\Core\Content\Media\MediaEntity;
use Shopware\Core\Content\Media\MediaService;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\DefinitionInstanceRegistry;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\System\Locale\LanguageLocaleCodeProvider;

/**
 * Writes a CMS page including every referenced image into a self-contained ZIP archive.
 */
class CmsPageExportService
{
    /**
     * @internal
     *
     * @param EntityRepository<CmsPageCollection> $cmsPageRepository
     * @param EntityRepository<MediaCollection> $mediaRepository
     */
    public function __construct(
        private readonly EntityRepository $cmsPageRepository,
        private readonly EntityRepository $mediaRepository,
        private readonly MediaService $mediaService,
        private readonly DefinitionInstanceRegistry $definitionRegistry,
        private readonly EntitySerializer $serializer,
        private readonly MediaReferenceCollector $mediaReferenceCollector,
        private readonly LanguageLocaleCodeProvider $languageLocaleCodeProvider,
        private readonly ClockInterface $clock,
        private readonly string $shopwareVersion
    ) {
    }

    /**
     * @return ExportResult the archive lives in a temporary file, the caller owns it and has to clean it up
     */
    public function export(string $cmsPageId, Context $context): ExportResult
    {
        $page = $this->loadPage($cmsPageId, $context);

        $payload = $this->serializePage($page);

        $warnings = [];
        $mediaEntries = $this->collectMedia($payload, $context, $warnings);

        $filePath = $this->writeArchive($page, $payload, $mediaEntries, $warnings);

        return new ExportResult(
            $filePath,
            $this->buildFileName($page),
            \count(array_filter($mediaEntries, static fn (array $entry): bool => $entry['file'] !== null)),
            $warnings
        );
    }

    private function loadPage(string $cmsPageId, Context $context): CmsPageEntity
    {
        $criteria = new Criteria([$cmsPageId]);
        $criteria->addAssociation('translations');
        $criteria->addAssociation('sections.blocks.slots.translations');
        $criteria->getAssociation('sections')->addSorting(new FieldSorting('position'));
        $criteria->getAssociation('sections.blocks')->addSorting(new FieldSorting('position'));

        $page = $this->cmsPageRepository->search($criteria, $context)->getEntities()->first();

        if ($page === null) {
            throw CmsImportExportException::cmsPageNotFound($cmsPageId);
        }

        return $page;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializePage(CmsPageEntity $page): array
    {
        $definition = $this->definitionRegistry->getByEntityName('cms_page');

        $payload = $this->serializer->serialize($definition, $page);
        $payload['translations'] = $this->serializeTranslations($page, 'cms_page_translation', ['cmsPageId']);
        $payload['sections'] = array_values(array_map(
            fn (CmsSectionEntity $section): array => $this->serializeSection($section),
            $page->getSections()?->getElements() ?? []
        ));

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeSection(CmsSectionEntity $section): array
    {
        $definition = $this->definitionRegistry->getByEntityName('cms_section');

        $payload = $this->serializer->serialize($definition, $section, ['pageId']);
        $payload['blocks'] = array_values(array_map(
            fn (CmsBlockEntity $block): array => $this->serializeBlock($block),
            $section->getBlocks()?->getElements() ?? []
        ));

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeBlock(CmsBlockEntity $block): array
    {
        $definition = $this->definitionRegistry->getByEntityName('cms_block');

        $payload = $this->serializer->serialize($definition, $block, ['sectionId']);
        $payload['slots'] = array_values(array_map(
            fn (CmsSlotEntity $slot): array => $this->serializeSlot($slot),
            $block->getSlots()?->getElements() ?? []
        ));

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeSlot(CmsSlotEntity $slot): array
    {
        $definition = $this->definitionRegistry->getByEntityName('cms_slot');

        $payload = $this->serializer->serialize($definition, $slot, ['blockId']);
        $payload['translations'] = $this->serializeTranslations($slot, 'cms_slot_translation', ['cmsSlotId']);

        return $payload;
    }

    /**
     * Translations are keyed by locale code instead of language id, so the archive stays portable between
     * installations that generated different language ids for the same locale.
     *
     * @param array<string> $skipProperties
     *
     * @return array<string, array<string, mixed>>
     */
    private function serializeTranslations(Entity $entity, string $translationEntityName, array $skipProperties): array
    {
        $translations = $entity->get('translations');
        if (!$translations instanceof EntityCollection) {
            return [];
        }

        $definition = $this->definitionRegistry->getByEntityName($translationEntityName);

        /** @var list<string> $languageIds */
        $languageIds = array_values(array_filter(array_map(
            static fn (Entity $translation): mixed => $translation->get('languageId'),
            $translations->getElements()
        ), \is_string(...)));

        $locales = $this->languageLocaleCodeProvider->getLocalesForLanguageIds($languageIds);

        $result = [];
        foreach ($translations as $translation) {
            $languageId = $translation->get('languageId');
            if (!\is_string($languageId) || !isset($locales[$languageId])) {
                continue;
            }

            $result[$locales[$languageId]] = $this->serializer->serialize(
                $definition,
                $translation,
                [...$skipProperties, 'languageId']
            );
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string> $warnings
     *
     * @return list<array<string, mixed>> manifest entries, ordered, with the archive path of each file
     */
    private function collectMedia(array $payload, Context $context, array &$warnings): array
    {
        $candidates = $this->mediaReferenceCollector->collectCandidates($payload);
        if ($candidates === []) {
            return [];
        }

        $criteria = new Criteria($candidates);
        $criteria->addAssociation('translations');
        $media = $this->mediaRepository->search($criteria, $context)->getEntities();

        $entries = [];
        $index = 0;
        foreach ($media as $mediaEntity) {
            ++$index;
            $entries[] = $this->buildMediaEntry($mediaEntity, $index, $context, $warnings);
        }

        return $entries;
    }

    /**
     * @param array<string> $warnings
     *
     * @return array<string, mixed>
     */
    private function buildMediaEntry(MediaEntity $mediaEntity, int $index, Context $context, array &$warnings): array
    {
        $fileName = $mediaEntity->getFileName();
        $extension = $mediaEntity->getFileExtension();

        $entry = [
            'id' => $mediaEntity->getId(),
            'file' => null,
            'fileName' => $fileName,
            'fileExtension' => $extension,
            'mimeType' => $mediaEntity->getMimeType(),
            'fileSize' => $mediaEntity->getFileSize(),
            'private' => $mediaEntity->isPrivate(),
            'metaData' => $mediaEntity->getMetaData(),
            'translations' => $this->serializeTranslations($mediaEntity, 'media_translation', ['mediaId']),
            'contents' => null,
        ];

        if ($fileName === null || $extension === null) {
            $warnings[] = \sprintf('Media "%s" has no file attached and was exported without an image.', $mediaEntity->getId());

            return $entry;
        }

        try {
            $contents = '';
            $mediaService = $this->mediaService;
            $context->scope(Context::SYSTEM_SCOPE, static function (Context $systemContext) use ($mediaService, $mediaEntity, &$contents): void {
                $contents = $mediaService->loadFile($mediaEntity->getId(), $systemContext);
            });
        } catch (\Throwable $exception) {
            $warnings[] = \sprintf(
                'The file of media "%s" (%s.%s) could not be read and was skipped: %s',
                $mediaEntity->getId(),
                $fileName,
                $extension,
                $exception->getMessage()
            );

            return $entry;
        }

        $entry['file'] = \sprintf('%s/%04d-%s.%s', CmsPageArchive::MEDIA_DIR, $index, $fileName, $extension);
        $entry['contents'] = $contents;

        return $entry;
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<array<string, mixed>> $mediaEntries
     * @param array<string> $warnings
     *
     * @return string path of the written archive
     */
    private function writeArchive(CmsPageEntity $page, array $payload, array $mediaEntries, array $warnings): string
    {
        $filePath = tempnam(sys_get_temp_dir(), 'frosh-cms-export-');
        if ($filePath === false) {
            throw CmsImportExportException::archiveWriteFailed(sys_get_temp_dir());
        }

        $archive = new \ZipArchive();
        if ($archive->open($filePath, \ZipArchive::OVERWRITE) !== true) {
            throw CmsImportExportException::archiveWriteFailed($filePath);
        }

        $manifestMedia = [];
        foreach ($mediaEntries as $entry) {
            $contents = $entry['contents'];
            unset($entry['contents']);
            $manifestMedia[] = $entry;

            if ($entry['file'] !== null && \is_string($contents)) {
                $archive->addFromString($entry['file'], $contents);
            }
        }

        $archive->addFromString(CmsPageArchive::MANIFEST_FILE, $this->encode([
            'formatVersion' => CmsPageArchive::FORMAT_VERSION,
            'generator' => [
                'plugin' => 'FroshCmsImportExport',
                'shopwareVersion' => $this->shopwareVersion,
            ],
            'exportedAt' => $this->clock->now()->format(\DATE_ATOM),
            'page' => [
                'id' => $page->getId(),
                'name' => $page->getTranslation('name'),
                'type' => $page->getType(),
            ],
            'media' => $manifestMedia,
            'warnings' => $warnings,
        ]));

        $archive->addFromString(CmsPageArchive::PAGE_FILE, $this->encode($payload));

        if (!$archive->close()) {
            throw CmsImportExportException::archiveWriteFailed($filePath);
        }

        return $filePath;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function encode(array $data): string
    {
        return json_encode($data, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
    }

    private function buildFileName(CmsPageEntity $page): string
    {
        $name = $page->getTranslation('name');
        $slug = \is_string($name) ? strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $name), '-')) : '';

        if ($slug === '') {
            $slug = $page->getId();
        }

        return \sprintf('cms-page-%s.zip', $slug);
    }
}
