<?php declare(strict_types=1);

namespace Frosh\CmsImportExport\Service;

use Frosh\CmsImportExport\Archive\CmsPageArchive;
use Frosh\CmsImportExport\Exception\CmsImportExportException;
use Frosh\CmsImportExport\Struct\ImportResult;
use Shopware\Core\Content\Cms\CmsPageCollection;
use Shopware\Core\Content\Media\Aggregate\MediaFolder\MediaFolderCollection;
use Shopware\Core\Content\Media\File\FileNameProvider;
use Shopware\Core\Content\Media\MediaCollection;
use Shopware\Core\Content\Media\MediaService;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\Language\LanguageCollection;

/**
 * Restores a CMS page from an archive written by {@see CmsPageExportService}.
 *
 * The import never reuses identities from the source system: every page, section, block and slot gets a fresh
 * UUID, and every image is stored as a new media entity. That makes importing an archive into the shop it was
 * exported from a safe copy operation instead of an overwrite.
 */
class CmsPageImportService
{
    /**
     * @internal
     *
     * @param EntityRepository<CmsPageCollection> $cmsPageRepository
     * @param EntityRepository<MediaCollection> $mediaRepository
     * @param EntityRepository<MediaFolderCollection> $mediaFolderRepository
     * @param EntityRepository<LanguageCollection> $languageRepository
     */
    public function __construct(
        private readonly EntityRepository $cmsPageRepository,
        private readonly EntityRepository $mediaRepository,
        private readonly EntityRepository $mediaFolderRepository,
        private readonly EntityRepository $languageRepository,
        private readonly MediaService $mediaService,
        private readonly FileNameProvider $fileNameProvider,
        private readonly MediaReferenceCollector $mediaReferenceCollector
    ) {
    }

    public function import(string $archivePath, Context $context, ?string $nameOverride = null): ImportResult
    {
        $archive = new \ZipArchive();
        if ($archive->open($archivePath) !== true) {
            throw CmsImportExportException::archiveNotReadable(basename($archivePath));
        }

        try {
            $manifest = $this->readJson($archive, CmsPageArchive::MANIFEST_FILE);
            $payload = $this->readJson($archive, CmsPageArchive::PAGE_FILE);

            $formatVersion = $manifest['formatVersion'] ?? 0;
            if (!\is_int($formatVersion) || $formatVersion > CmsPageArchive::FORMAT_VERSION) {
                throw CmsImportExportException::unsupportedFormatVersion((int) $formatVersion, CmsPageArchive::FORMAT_VERSION);
            }

            $warnings = [];
            $reusedMediaCount = 0;
            $mediaMap = $this->importMedia($archive, $manifest, $context, $warnings, $reusedMediaCount);
        } finally {
            $archive->close();
        }

        $payload = $this->mediaReferenceCollector->replace($payload, $mediaMap);
        $payload = $this->dropUnknownTranslations($payload, $context, $warnings);

        $cmsPageId = Uuid::randomHex();
        $payload['id'] = $cmsPageId;

        if ($nameOverride !== null && $nameOverride !== '') {
            $payload = $this->applyNameOverride($payload, $nameOverride);
        }

        $this->cmsPageRepository->create([$payload], $context);

        return new ImportResult(
            $cmsPageId,
            $this->resolveName($payload, $manifest),
            \count(array_filter($mediaMap, static fn (?string $id): bool => $id !== null)),
            $reusedMediaCount,
            $warnings
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(\ZipArchive $archive, string $file): array
    {
        $contents = $archive->getFromName($file);
        if ($contents === false) {
            throw CmsImportExportException::archiveIncomplete($file);
        }

        try {
            $decoded = json_decode($contents, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw CmsImportExportException::archiveInvalidJson($file);
        }

        if (!\is_array($decoded)) {
            throw CmsImportExportException::archiveInvalidJson($file);
        }

        return $decoded;
    }

    /**
     * Images already present in the media library are reused instead of copied. Shopware stores an MD5 of every
     * uploaded file in `media.file_hash` (a generated column over `meta_data.hash`, written synchronously by the
     * FileSaver and backed by an index), which is the same digest as an MD5 over the raw bytes in the archive.
     * That makes an exact-content match cheap to look up and keeps repeated imports from filling the media
     * library with byte-identical duplicates.
     *
     * @param array<string, mixed> $manifest
     * @param array<string> $warnings
     *
     * @return array<string, string|null> original media id => media id in this shop, or null when the image is unavailable
     */
    private function importMedia(
        \ZipArchive $archive,
        array $manifest,
        Context $context,
        array &$warnings,
        int &$reusedCount
    ): array {
        $entries = $manifest['media'] ?? [];
        if (!\is_array($entries) || $entries === []) {
            return [];
        }

        $map = [];
        $hashes = [];

        // First pass: hash every image without keeping the blobs around, so the lookup below is a single query
        // and memory stays at one image at a time regardless of how many the layout uses.
        foreach ($entries as $entry) {
            if (!\is_array($entry) || !\is_string($entry['id'] ?? null)) {
                continue;
            }

            $originalId = $entry['id'];
            $map[$originalId] = null;

            $contents = $this->readImage($archive, $entry, $warnings);
            if ($contents === null) {
                continue;
            }

            $hashes[$originalId] = $this->reuseKey(md5($contents), (bool) ($entry['private'] ?? false));
        }

        if ($hashes === []) {
            return $map;
        }

        $known = $this->findMediaByContentHash($hashes, $context);

        $folderId = null;
        $availableLocales = null;

        foreach ($entries as $entry) {
            if (!\is_array($entry) || !\is_string($entry['id'] ?? null)) {
                continue;
            }

            $originalId = $entry['id'];
            if (!isset($hashes[$originalId])) {
                continue;
            }

            $reuseKey = $hashes[$originalId];
            if (isset($known[$reuseKey])) {
                $map[$originalId] = $known[$reuseKey];
                ++$reusedCount;

                continue;
            }

            $contents = $this->readImage($archive, $entry, $warnings);
            if ($contents === null) {
                continue;
            }

            // Resolved lazily so an import that reuses everything does not query for them at all.
            $folderId ??= $this->resolveCmsMediaFolderId($context);
            $availableLocales ??= $this->getAvailableLocales($context);

            $map[$originalId] = $this->createMedia($entry, $contents, $folderId, $availableLocales, $context);

            // Two entries in the same archive can carry identical bytes; the second one reuses the first.
            $known[$reuseKey] = $map[$originalId];
        }

        return $map;
    }

    /**
     * @param array<string, mixed> $entry
     * @param array<string> $warnings
     */
    private function readImage(\ZipArchive $archive, array $entry, array &$warnings): ?string
    {
        $file = $entry['file'] ?? null;

        if (!\is_string($file) || !\is_string($entry['fileName'] ?? null) || !\is_string($entry['fileExtension'] ?? null)) {
            $warnings[] = \sprintf('The archive contains no image for media "%s", the reference was cleared.', $entry['id']);

            return null;
        }

        $contents = $archive->getFromName($file);
        if ($contents === false) {
            $warnings[] = \sprintf('The file "%s" is missing in the archive, the reference was cleared.', $file);

            return null;
        }

        return $contents;
    }

    /**
     * @param array<string, mixed> $entry
     * @param array<string, bool> $availableLocales
     */
    private function createMedia(
        array $entry,
        string $contents,
        ?string $folderId,
        array $availableLocales,
        Context $context
    ): string {
        $mediaId = Uuid::randomHex();
        $private = (bool) ($entry['private'] ?? false);

        $this->mediaRepository->create([[
            'id' => $mediaId,
            'private' => $private,
            'mediaFolderId' => $folderId,
            'translations' => $this->filterTranslations($entry['translations'] ?? [], $availableLocales),
        ]], $context);

        $this->mediaService->saveFile(
            $contents,
            (string) $entry['fileExtension'],
            \is_string($entry['mimeType'] ?? null) ? $entry['mimeType'] : 'application/octet-stream',
            $this->fileNameProvider->provide((string) $entry['fileName'], (string) $entry['fileExtension'], $mediaId, $context),
            $context,
            null,
            $mediaId,
            $private
        );

        return $mediaId;
    }

    /**
     * Private and public media are never interchangeable, even with identical content, so visibility is part of
     * the identity a reuse candidate has to match.
     */
    private function reuseKey(string $hash, bool $private): string
    {
        return $hash . ':' . ($private ? 'private' : 'public');
    }

    /**
     * @param array<string, string> $reuseKeys original media id => reuse key
     *
     * @return array<string, string> reuse key => existing media id
     */
    private function findMediaByContentHash(array $reuseKeys, Context $context): array
    {
        $hashes = array_values(array_unique(array_map(
            static fn (string $key): string => explode(':', $key)[0],
            $reuseKeys
        )));

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsAnyFilter('fileHash', $hashes));

        $found = [];
        foreach ($this->mediaRepository->search($criteria, $context)->getEntities() as $media) {
            $hash = $media->getFileHash();
            if ($hash === null) {
                continue;
            }

            // Keep the first match per key; which of several identical files wins does not matter.
            $found[$this->reuseKey($hash, $media->isPrivate())] ??= $media->getId();
        }

        return $found;
    }

    private function resolveCmsMediaFolderId(Context $context): ?string
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('media_folder.defaultFolder.entity', 'cms_page'));
        $criteria->setLimit(1);

        return $this->mediaFolderRepository->searchIds($criteria, $context)->firstId();
    }

    /**
     * @return array<string, bool> locale code => true
     */
    private function getAvailableLocales(Context $context): array
    {
        $criteria = new Criteria();
        $criteria->addAssociation('locale');

        $locales = [];
        foreach ($this->languageRepository->search($criteria, $context)->getEntities() as $language) {
            $code = $language->getLocale()?->getCode();
            if ($code !== null) {
                $locales[$code] = true;
            }
        }

        return $locales;
    }

    /**
     * An archive can carry translations for locales the target shop does not have. Writing those would abort the
     * whole import, so they are dropped and reported instead.
     *
     * @param array<string, mixed> $payload
     * @param array<string> $warnings
     *
     * @return array<string, mixed>
     */
    private function dropUnknownTranslations(array $payload, Context $context, array &$warnings): array
    {
        $availableLocales = $this->getAvailableLocales($context);
        $dropped = [];

        $payload = $this->walkTranslations($payload, $availableLocales, $dropped);

        if ($dropped !== []) {
            $warnings[] = \sprintf(
                'The archive contains translations for locales that are not installed and were skipped: %s',
                implode(', ', array_keys($dropped))
            );
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, bool> $availableLocales
     * @param array<string, bool> $dropped
     *
     * @return array<string, mixed>
     */
    private function walkTranslations(array $node, array $availableLocales, array &$dropped): array
    {
        foreach ($node as $key => $value) {
            if (!\is_array($value)) {
                continue;
            }

            if ($key === 'translations') {
                $node[$key] = $this->filterTranslations($value, $availableLocales, $dropped);

                continue;
            }

            $node[$key] = $this->walkTranslations($value, $availableLocales, $dropped);
        }

        return $node;
    }

    /**
     * @param array<string, bool> $availableLocales
     * @param array<string, bool> $dropped
     *
     * @return array<string, mixed>
     */
    private function filterTranslations(mixed $translations, array $availableLocales, array &$dropped = []): array
    {
        if (!\is_array($translations)) {
            return [];
        }

        $result = [];
        foreach ($translations as $locale => $translation) {
            if (!\is_string($locale) || !\is_array($translation)) {
                continue;
            }

            if (!isset($availableLocales[$locale])) {
                $dropped[$locale] = true;

                continue;
            }

            $result[$locale] = $translation;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function applyNameOverride(array $payload, string $name): array
    {
        $translations = $payload['translations'] ?? [];

        if (!\is_array($translations) || $translations === []) {
            // No portable translation survived, fall back to the language of the current context.
            $payload['name'] = $name;

            return $payload;
        }

        foreach ($translations as $locale => $translation) {
            if (\is_array($translation)) {
                $translations[$locale]['name'] = $name;
            }
        }

        $payload['translations'] = $translations;

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $manifest
     */
    private function resolveName(array $payload, array $manifest): string
    {
        if (\is_string($payload['name'] ?? null)) {
            return $payload['name'];
        }

        $translations = $payload['translations'] ?? [];
        if (\is_array($translations)) {
            foreach ($translations as $translation) {
                if (\is_array($translation) && \is_string($translation['name'] ?? null)) {
                    return $translation['name'];
                }
            }
        }

        $manifestName = $manifest['page']['name'] ?? null;

        return \is_string($manifestName) ? $manifestName : 'Imported CMS page';
    }
}
