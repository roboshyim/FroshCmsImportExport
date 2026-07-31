<?php declare(strict_types=1);

namespace Frosh\CmsImportExport\Controller;

use Frosh\CmsImportExport\Exception\CmsImportExportException;
use Frosh\CmsImportExport\Service\CmsPageExportService;
use Frosh\CmsImportExport\Service\CmsPageImportService;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Routing\ApiRouteScope;
use Shopware\Core\PlatformRequest;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [ApiRouteScope::ID]])]
class CmsImportExportController extends AbstractController
{
    /**
     * @internal
     */
    public function __construct(
        private readonly CmsPageExportService $exportService,
        private readonly CmsPageImportService $importService
    ) {
    }

    #[Route(
        path: '/api/_action/frosh-cms-import-export/export/{cmsPageId}',
        name: 'api.action.frosh_cms_import_export.export',
        defaults: [PlatformRequest::ATTRIBUTE_ACL => ['cms.viewer']],
        methods: ['GET']
    )]
    public function export(string $cmsPageId, Context $context): Response
    {
        $result = $this->exportService->export($cmsPageId, $context);

        $response = new BinaryFileResponse($result->filePath);
        $response->deleteFileAfterSend(true);
        $response->headers->set('Content-Type', 'application/zip');
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $result->fileName);

        // A BinaryFileResponse cannot be serialized, so it must never reach the HTTP cache store.
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store', true);

        // Warnings cannot travel in the body of a file download, so they are exposed as headers.
        $response->headers->set('sw-media-count', (string) $result->mediaCount);
        if ($result->warnings !== []) {
            $response->headers->set('sw-export-warnings', json_encode($result->warnings, \JSON_THROW_ON_ERROR));
        }

        return $response;
    }

    #[Route(
        path: '/api/_action/frosh-cms-import-export/import',
        name: 'api.action.frosh_cms_import_export.import',
        defaults: [PlatformRequest::ATTRIBUTE_ACL => ['cms.creator']],
        methods: ['POST']
    )]
    public function import(Request $request, Context $context): JsonResponse
    {
        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            throw CmsImportExportException::noUpload();
        }

        $name = $request->request->get('name');
        $result = $this->importService->import(
            $file->getPathname(),
            $context,
            \is_string($name) ? $name : null
        );

        return new JsonResponse([
            'cmsPageId' => $result->cmsPageId,
            'name' => $result->name,
            'mediaCount' => $result->mediaCount,
            'reusedMediaCount' => $result->reusedMediaCount,
            'warnings' => $result->warnings,
        ]);
    }
}
