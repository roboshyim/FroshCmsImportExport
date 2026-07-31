<?php declare(strict_types=1);

namespace Frosh\CmsImportExport\Struct;

use Shopware\Core\Framework\Struct\Struct;

class ImportResult extends Struct
{
    /**
     * @param int $mediaCount images the imported layout references
     * @param int $reusedMediaCount how many of those were matched to images already in the media library
     * @param array<string> $warnings
     */
    public function __construct(
        public readonly string $cmsPageId,
        public readonly string $name,
        public readonly int $mediaCount,
        public readonly int $reusedMediaCount = 0,
        public readonly array $warnings = []
    ) {
    }
}
