<?php declare(strict_types=1);

namespace Frosh\CmsImportExport\Test\Integration\Service;

use Frosh\CmsImportExport\Archive\CmsPageArchive;
use Frosh\CmsImportExport\Exception\CmsImportExportException;
use Frosh\CmsImportExport\Service\CmsPageExportService;
use Frosh\CmsImportExport\Service\CmsPageImportService;
use Frosh\CmsImportExport\Struct\ExportResult;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\Cms\CmsPageCollection;
use Shopware\Core\Content\Cms\CmsPageEntity;
use Shopware\Core\Content\Media\MediaCollection;
use Shopware\Core\Content\Media\MediaService;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\Filesystem\Filesystem;

/**
 * @internal
 */
class CmsPageImportExportTest extends TestCase
{
    use IntegrationTestBehaviour;

    private const PNG = "\x89PNG\r\n\x1a\n" . 'frosh-cms-import-export-fixture';

    private CmsPageExportService $exportService;

    private CmsPageImportService $importService;

    private MediaService $mediaService;

    /**
     * @var EntityRepository<CmsPageCollection>
     */
    private EntityRepository $cmsPageRepository;

    /**
     * @var EntityRepository<MediaCollection>
     */
    private EntityRepository $mediaRepository;

    private Context $context;

    private Filesystem $filesystem;

    /**
     * @var array<string>
     */
    private array $createdArchives = [];

    protected function setUp(): void
    {
        $this->exportService = static::getContainer()->get(CmsPageExportService::class);
        $this->importService = static::getContainer()->get(CmsPageImportService::class);
        $this->mediaService = static::getContainer()->get(MediaService::class);
        $this->cmsPageRepository = static::getContainer()->get('cms_page.repository');
        $this->mediaRepository = static::getContainer()->get('media.repository');
        $this->context = Context::createDefaultContext();
        $this->filesystem = new Filesystem();
    }

    protected function tearDown(): void
    {
        foreach ($this->createdArchives as $archive) {
            $this->filesystem->remove($archive);
        }

        $this->createdArchives = [];
    }

    public function testExportProducesASelfContainedArchive(): void
    {
        $mediaId = $this->createMediaWithFile('frosh-hero');
        $cmsPageId = $this->createCmsPage('Hero layout', $mediaId);

        $result = $this->export($cmsPageId);

        static::assertSame('cms-page-hero-layout.zip', $result->fileName);
        static::assertSame(1, $result->mediaCount);
        static::assertSame([], $result->warnings);

        $archive = new \ZipArchive();
        static::assertTrue($archive->open($result->filePath));

        $manifest = json_decode((string) $archive->getFromName(CmsPageArchive::MANIFEST_FILE), true, 512, \JSON_THROW_ON_ERROR);
        static::assertSame(CmsPageArchive::FORMAT_VERSION, $manifest['formatVersion']);
        static::assertSame('Hero layout', $manifest['page']['name']);
        static::assertCount(1, $manifest['media']);

        $mediaFile = $manifest['media'][0]['file'];
        static::assertSame(self::PNG, $archive->getFromName($mediaFile));
        static::assertNotFalse($archive->getFromName(CmsPageArchive::PAGE_FILE));

        $archive->close();
    }

    public function testImportRestoresTheLayoutWithFreshIdentities(): void
    {
        $mediaId = $this->createMediaWithFile('frosh-roundtrip');
        $cmsPageId = $this->createCmsPage('Roundtrip layout', $mediaId);

        $result = $this->importService->import($this->export($cmsPageId)->filePath, $this->context);

        static::assertNotSame($cmsPageId, $result->cmsPageId);
        static::assertSame('Roundtrip layout', $result->name);
        static::assertSame(1, $result->mediaCount);
        static::assertSame([], $result->warnings);

        $imported = $this->loadCmsPage($result->cmsPageId);
        $sections = $imported->getSections();
        static::assertNotNull($sections);
        static::assertCount(1, $sections);

        $section = $sections->first();
        static::assertNotNull($section);
        static::assertSame('boxed', $section->getSizingMode());

        $blocks = $section->getBlocks();
        static::assertNotNull($blocks);
        static::assertCount(1, $blocks);

        $slots = $blocks->first()?->getSlots();
        static::assertNotNull($slots);
        static::assertCount(1, $slots);

        $slot = $slots->first();
        static::assertNotNull($slot);
        static::assertSame('image', $slot->getType());
    }

    public function testImportReusesAnImageThatIsAlreadyInTheMediaLibrary(): void
    {
        $mediaId = $this->createMediaWithFile('frosh-reuse');
        $cmsPageId = $this->createCmsPage('Reuse layout', $mediaId);

        $result = $this->importService->import($this->export($cmsPageId)->filePath, $this->context);

        static::assertSame(1, $result->mediaCount);
        static::assertSame(1, $result->reusedMediaCount);
        static::assertSame($mediaId, $this->loadCmsPage($result->cmsPageId)->getPreviewMediaId());
    }

    public function testImportCreatesTheImageWhenNothingMatchesItsContent(): void
    {
        $mediaId = $this->createMediaWithFile('frosh-unknown');
        $cmsPageId = $this->createCmsPage('Unknown image layout', $mediaId);
        $archivePath = $this->export($cmsPageId)->filePath;

        // Removing the source layout and its image leaves the archive as the only place the bytes exist.
        $this->cmsPageRepository->delete([['id' => $cmsPageId]], $this->context);
        $this->mediaRepository->delete([['id' => $mediaId]], $this->context);

        $result = $this->importService->import($archivePath, $this->context);

        static::assertSame(1, $result->mediaCount);
        static::assertSame(0, $result->reusedMediaCount);

        $importedMediaId = $this->loadCmsPage($result->cmsPageId)->getPreviewMediaId();
        static::assertNotNull($importedMediaId);
        static::assertNotSame($mediaId, $importedMediaId);
        static::assertSame(self::PNG, $this->mediaService->loadFile($importedMediaId, $this->context));
    }

    public function testImportDoesNotReuseAPrivateImageForAPublicOne(): void
    {
        $privateMediaId = $this->createMediaWithFile('frosh-private', private: true);
        $cmsPageId = $this->createCmsPage('Private image layout', $privateMediaId);
        $archivePath = $this->export($cmsPageId)->filePath;

        // Same bytes, but public — the private original must not be picked up as a match.
        $this->createMediaWithFile('frosh-public-twin');

        $result = $this->importService->import($archivePath, $this->context);

        static::assertSame(1, $result->reusedMediaCount);
        static::assertSame($privateMediaId, $this->loadCmsPage($result->cmsPageId)->getPreviewMediaId());
    }

    public function testImportRemapsMediaIdsBuriedInSlotConfigs(): void
    {
        $mediaId = $this->createMediaWithFile('frosh-slot-config');
        $cmsPageId = $this->createCmsPage('Slot config layout', $mediaId);

        $result = $this->importService->import($this->export($cmsPageId)->filePath, $this->context);

        $imported = $this->loadCmsPage($result->cmsPageId);
        $slot = $imported->getSections()?->first()?->getBlocks()?->first()?->getSlots()?->first();
        static::assertNotNull($slot);

        $config = $slot->getTranslation('config');
        static::assertIsArray($config);
        static::assertSame($imported->getPreviewMediaId(), $config['media']['value']);
    }

    public function testImportingTheSameArchiveRepeatedlyDoesNotGrowTheMediaLibrary(): void
    {
        $mediaId = $this->createMediaWithFile('frosh-twice');
        $archivePath = $this->export($this->createCmsPage('Twice layout', $mediaId))->filePath;

        $before = $this->countMediaWithFixtureContent();

        $first = $this->importService->import($archivePath, $this->context);
        $second = $this->importService->import($archivePath, $this->context);

        static::assertNotSame($first->cmsPageId, $second->cmsPageId);
        static::assertSame(1, $second->mediaCount);
        static::assertSame(1, $second->reusedMediaCount);
        static::assertSame([], $second->warnings);
        static::assertSame($before, $this->countMediaWithFixtureContent());
    }

    public function testImportAppliesTheNameOverrideToEveryTranslation(): void
    {
        $mediaId = $this->createMediaWithFile('frosh-renamed');
        $cmsPageId = $this->createCmsPage('Original name', $mediaId);

        $result = $this->importService->import($this->export($cmsPageId)->filePath, $this->context, 'Renamed on import');

        static::assertSame('Renamed on import', $result->name);
        static::assertSame('Renamed on import', $this->loadCmsPage($result->cmsPageId)->getTranslation('name'));
    }

    public function testExportWarnsAboutMediaWithoutAFileAndImportClearsTheReference(): void
    {
        $mediaId = Uuid::randomHex();
        $this->mediaRepository->create([['id' => $mediaId, 'private' => false]], $this->context);
        $cmsPageId = $this->createCmsPage('Broken media layout', $mediaId);

        $exportResult = $this->export($cmsPageId);

        static::assertSame(0, $exportResult->mediaCount);
        static::assertCount(1, $exportResult->warnings);
        static::assertStringContainsString('has no file attached', $exportResult->warnings[0]);

        $importResult = $this->importService->import($exportResult->filePath, $this->context);

        static::assertSame(0, $importResult->mediaCount);
        static::assertNull($this->loadCmsPage($importResult->cmsPageId)->getPreviewMediaId());
    }

    public function testExportFailsForAnUnknownCmsPage(): void
    {
        $cmsPageId = Uuid::randomHex();

        $this->expectExceptionObject(CmsImportExportException::cmsPageNotFound($cmsPageId));

        $this->exportService->export($cmsPageId, $this->context);
    }

    public function testImportRejectsAFileThatIsNoZipArchive(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'frosh-not-a-zip-');
        $this->createdArchives[] = $path;
        $this->filesystem->dumpFile($path, 'this is not a zip');

        $this->expectExceptionObject(CmsImportExportException::archiveNotReadable(basename($path)));

        $this->importService->import($path, $this->context);
    }

    public function testImportRejectsAnArchiveWithoutAManifest(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'frosh-empty-zip-');
        $this->createdArchives[] = $path;

        $archive = new \ZipArchive();
        $archive->open($path, \ZipArchive::OVERWRITE);
        $archive->addFromString('readme.txt', 'nothing to see here');
        $archive->close();

        $this->expectExceptionObject(CmsImportExportException::archiveIncomplete(CmsPageArchive::MANIFEST_FILE));

        $this->importService->import($path, $this->context);
    }

    public function testImportRejectsAnArchiveFromANewerFormatVersion(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'frosh-future-zip-');
        $this->createdArchives[] = $path;

        $archive = new \ZipArchive();
        $archive->open($path, \ZipArchive::OVERWRITE);
        $archive->addFromString(CmsPageArchive::MANIFEST_FILE, json_encode([
            'formatVersion' => CmsPageArchive::FORMAT_VERSION + 1,
            'media' => [],
        ], \JSON_THROW_ON_ERROR));
        $archive->addFromString(CmsPageArchive::PAGE_FILE, '{"type":"page"}');
        $archive->close();

        $this->expectExceptionObject(CmsImportExportException::unsupportedFormatVersion(
            CmsPageArchive::FORMAT_VERSION + 1,
            CmsPageArchive::FORMAT_VERSION
        ));

        $this->importService->import($path, $this->context);
    }

    private function export(string $cmsPageId): ExportResult
    {
        $result = $this->exportService->export($cmsPageId, $this->context);
        $this->createdArchives[] = $result->filePath;

        return $result;
    }

    private function createMediaWithFile(string $fileName, bool $private = false): string
    {
        return $this->mediaService->saveFile(
            self::PNG,
            'png',
            'image/png',
            $fileName . '-' . Uuid::randomHex(),
            $this->context,
            'cms_page',
            null,
            $private
        );
    }

    private function countMediaWithFixtureContent(): int
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('fileHash', md5(self::PNG)));

        return $this->mediaRepository->searchIds($criteria, $this->context)->getTotal();
    }

    /**
     * Builds a layout that references the same media through a foreign key and through a slot config, which are
     * the two ways a CMS layout can point at an image.
     */
    private function createCmsPage(string $name, string $mediaId): string
    {
        $cmsPageId = Uuid::randomHex();

        $this->cmsPageRepository->create([[
            'id' => $cmsPageId,
            'name' => $name,
            'type' => 'page',
            'previewMediaId' => $mediaId,
            'sections' => [[
                'position' => 0,
                'type' => 'default',
                'sizingMode' => 'boxed',
                'blocks' => [[
                    'position' => 0,
                    'type' => 'image-text',
                    'name' => 'Hero',
                    'sectionPosition' => 'main',
                    'slots' => [[
                        'type' => 'image',
                        'slot' => 'left',
                        'config' => [
                            'media' => ['source' => 'static', 'value' => $mediaId],
                            'displayMode' => ['source' => 'static', 'value' => 'standard'],
                        ],
                    ]],
                ]],
            ]],
        ]], $this->context);

        return $cmsPageId;
    }

    private function loadCmsPage(string $cmsPageId): CmsPageEntity
    {
        $criteria = new Criteria([$cmsPageId]);
        $criteria->addAssociation('sections.blocks.slots');

        $page = $this->cmsPageRepository->search($criteria, $this->context)->getEntities()->first();
        static::assertNotNull($page);

        return $page;
    }
}
