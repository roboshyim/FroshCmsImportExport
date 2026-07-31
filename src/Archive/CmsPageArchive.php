<?php declare(strict_types=1);

namespace Frosh\CmsImportExport\Archive;

/**
 * Layout of the exported ZIP archive.
 *
 * frosh-cms-page-<name>.zip
 * ├── manifest.json   metadata + index of every contained media file
 * ├── page.json       the CMS page payload, ready to be written through the DAL
 * └── media/          every referenced media file, named "<index>-<fileName>.<ext>"
 */
final class CmsPageArchive
{
    public const FORMAT_VERSION = 1;

    public const MANIFEST_FILE = 'manifest.json';

    public const PAGE_FILE = 'page.json';

    public const MEDIA_DIR = 'media';

    private function __construct()
    {
    }
}
