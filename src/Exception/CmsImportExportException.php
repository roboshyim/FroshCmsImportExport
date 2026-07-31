<?php declare(strict_types=1);

namespace Frosh\CmsImportExport\Exception;

use Shopware\Core\Framework\HttpException;
use Symfony\Component\HttpFoundation\Response;

class CmsImportExportException extends HttpException
{
    final public const CMS_PAGE_NOT_FOUND = 'FROSH_CMS__PAGE_NOT_FOUND';
    final public const ARCHIVE_NOT_READABLE = 'FROSH_CMS__ARCHIVE_NOT_READABLE';
    final public const ARCHIVE_INCOMPLETE = 'FROSH_CMS__ARCHIVE_INCOMPLETE';
    final public const ARCHIVE_INVALID_JSON = 'FROSH_CMS__ARCHIVE_INVALID_JSON';
    final public const UNSUPPORTED_FORMAT_VERSION = 'FROSH_CMS__UNSUPPORTED_FORMAT_VERSION';
    final public const NO_UPLOAD = 'FROSH_CMS__NO_UPLOAD';
    final public const ARCHIVE_WRITE_FAILED = 'FROSH_CMS__ARCHIVE_WRITE_FAILED';

    public static function cmsPageNotFound(string $cmsPageId): self
    {
        return new self(
            Response::HTTP_NOT_FOUND,
            self::CMS_PAGE_NOT_FOUND,
            'The CMS page with id "{{ cmsPageId }}" was not found.',
            ['cmsPageId' => $cmsPageId]
        );
    }

    public static function archiveNotReadable(string $path): self
    {
        return new self(
            Response::HTTP_BAD_REQUEST,
            self::ARCHIVE_NOT_READABLE,
            'The archive "{{ path }}" could not be opened as a ZIP file.',
            ['path' => $path]
        );
    }

    public static function archiveIncomplete(string $file): self
    {
        return new self(
            Response::HTTP_BAD_REQUEST,
            self::ARCHIVE_INCOMPLETE,
            'The archive is incomplete, the file "{{ file }}" is missing.',
            ['file' => $file]
        );
    }

    public static function archiveInvalidJson(string $file): self
    {
        return new self(
            Response::HTTP_BAD_REQUEST,
            self::ARCHIVE_INVALID_JSON,
            'The file "{{ file }}" in the archive does not contain valid JSON.',
            ['file' => $file]
        );
    }

    public static function unsupportedFormatVersion(int $version, int $supported): self
    {
        return new self(
            Response::HTTP_BAD_REQUEST,
            self::UNSUPPORTED_FORMAT_VERSION,
            'The archive uses format version {{ version }}, but only version {{ supported }} and below is supported.',
            ['version' => $version, 'supported' => $supported]
        );
    }

    public static function noUpload(): self
    {
        return new self(
            Response::HTTP_BAD_REQUEST,
            self::NO_UPLOAD,
            'No file was uploaded. Send the archive as multipart form field "file".'
        );
    }

    public static function archiveWriteFailed(string $path): self
    {
        return new self(
            Response::HTTP_INTERNAL_SERVER_ERROR,
            self::ARCHIVE_WRITE_FAILED,
            'The archive could not be written to "{{ path }}".',
            ['path' => $path]
        );
    }
}
