<?php declare(strict_types=1);

namespace Frosh\CmsImportExport\Struct;

use Shopware\Core\Framework\Struct\Struct;

class ExportResult extends Struct
{
    /**
     * @param array<string> $warnings
     */
    public function __construct(
        public readonly string $filePath,
        public readonly string $fileName,
        public readonly int $mediaCount,
        public readonly array $warnings = []
    ) {
    }
}
