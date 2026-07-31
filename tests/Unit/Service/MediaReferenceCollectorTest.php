<?php declare(strict_types=1);

namespace Frosh\CmsImportExport\Test\Unit\Service;

use Frosh\CmsImportExport\Service\MediaReferenceCollector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MediaReferenceCollector::class)]
class MediaReferenceCollectorTest extends TestCase
{
    private MediaReferenceCollector $collector;

    protected function setUp(): void
    {
        $this->collector = new MediaReferenceCollector();
    }

    public function testCollectsForeignKeysAndIdsBuriedInSlotConfigs(): void
    {
        $payload = [
            'previewMediaId' => '0102030405060708090a0b0c0d0e0f10',
            'sections' => [
                [
                    'backgroundMediaId' => '1112131415161718191a1b1c1d1e1f20',
                    'blocks' => [
                        [
                            'slots' => [
                                [
                                    'type' => 'image-slider',
                                    'translations' => [
                                        'en-GB' => [
                                            'config' => [
                                                'sliderItems' => [
                                                    'source' => 'static',
                                                    'value' => [
                                                        ['mediaId' => '2122232425262728292a2b2c2d2e2f30'],
                                                    ],
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $candidates = $this->collector->collectCandidates($payload);
        sort($candidates);

        static::assertSame([
            '0102030405060708090a0b0c0d0e0f10',
            '1112131415161718191a1b1c1d1e1f20',
            '2122232425262728292a2b2c2d2e2f30',
        ], $candidates);
    }

    public function testIgnoresMappedSourcesAndOtherNonUuidStrings(): void
    {
        $payload = [
            'config' => [
                'media' => ['source' => 'mapped', 'value' => 'category.media'],
                'minHeight' => ['source' => 'static', 'value' => '320px'],
                'newTab' => ['source' => 'static', 'value' => false],
            ],
        ];

        static::assertSame([], $this->collector->collectCandidates($payload));
    }

    public function testReportsEachIdOnlyOnce(): void
    {
        $mediaId = '0102030405060708090a0b0c0d0e0f10';

        $candidates = $this->collector->collectCandidates([
            'previewMediaId' => $mediaId,
            'sections' => [['backgroundMediaId' => $mediaId]],
        ]);

        static::assertSame([$mediaId], $candidates);
    }

    public function testReplaceRewritesEveryOccurrenceAndKeepsUnknownIds(): void
    {
        $known = '0102030405060708090a0b0c0d0e0f10';
        $unknown = '3132333435363738393a3b3c3d3e3f40';

        $result = $this->collector->replace(
            [
                'previewMediaId' => $known,
                'sections' => [
                    ['backgroundMediaId' => $known, 'productId' => $unknown],
                ],
            ],
            [$known => 'aabbccddeeff00112233445566778899']
        );

        static::assertSame([
            'previewMediaId' => 'aabbccddeeff00112233445566778899',
            'sections' => [
                ['backgroundMediaId' => 'aabbccddeeff00112233445566778899', 'productId' => $unknown],
            ],
        ], $result);
    }

    public function testReplaceClearsReferencesMappedToNull(): void
    {
        $mediaId = '0102030405060708090a0b0c0d0e0f10';

        $result = $this->collector->replace(
            ['previewMediaId' => $mediaId],
            [$mediaId => null]
        );

        static::assertSame(['previewMediaId' => null], $result);
    }
}
