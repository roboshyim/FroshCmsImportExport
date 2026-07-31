<?php declare(strict_types=1);

namespace Frosh\CmsImportExport\Struct;

use Shopware\Core\Framework\Struct\Struct;

class ImportResult extends Struct
{
    /**
     * @param array<string> $warnings
     */
    public function __construct(
        public readonly string $cmsPageId,
        public readonly string $name,
        public readonly int $mediaCount,
        public readonly array $warnings = []
    ) {
    }
}
