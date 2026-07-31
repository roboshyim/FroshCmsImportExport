import type { AxiosInstance } from 'axios';
import FroshCmsImportExportApiService from './frosh-cms-import-export.api.service';

describe('frosh-cms-import-export.api.service', () => {
    let httpClient: { get: jest.Mock; post: jest.Mock };
    let service: FroshCmsImportExportApiService;
    let createObjectURL: jest.Mock;
    let revokeObjectURL: jest.Mock;

    beforeEach(() => {
        httpClient = { get: jest.fn(), post: jest.fn() };
        service = new FroshCmsImportExportApiService(httpClient as unknown as AxiosInstance, {});

        createObjectURL = jest.fn().mockReturnValue('blob:cms-export');
        revokeObjectURL = jest.fn();
        Object.assign(window.URL, { createObjectURL, revokeObjectURL });
    });

    function exportResponse(headers: Record<string, string> = {}) {
        return { data: new Blob(['zip']), headers };
    }

    it('requests the archive as a blob', async () => {
        httpClient.get.mockResolvedValue(exportResponse());

        await service.exportPage('0102030405060708090a0b0c0d0e0f10');

        expect(httpClient.get).toHaveBeenCalledWith(
            '/_action/frosh-cms-import-export/export/0102030405060708090a0b0c0d0e0f10',
            expect.objectContaining({ responseType: 'blob' }),
        );
    });

    it('downloads the archive under the file name the server sent', async () => {
        httpClient.get.mockResolvedValue(
            exportResponse({ 'content-disposition': 'attachment; filename=cms-page-hero-layout.zip' }),
        );
        const click = jest.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {});

        await service.exportPage('0102030405060708090a0b0c0d0e0f10');

        expect(click).toHaveBeenCalled();
        expect(createObjectURL).toHaveBeenCalled();
        expect(revokeObjectURL).toHaveBeenCalledWith('blob:cms-export');
        expect(document.querySelector('a')).toBeNull();

        click.mockRestore();
    });

    it('falls back to a generic file name when the server sends no disposition', async () => {
        httpClient.get.mockResolvedValue(exportResponse());
        const links: string[] = [];
        jest.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function (this: HTMLAnchorElement) {
            links.push(this.download);
        });

        await service.exportPage('0102030405060708090a0b0c0d0e0f10');

        expect(links).toEqual(['cms-page.zip']);
    });

    it('returns the export warnings the server reported', async () => {
        httpClient.get.mockResolvedValue(
            exportResponse({ 'sw-export-warnings': JSON.stringify(['Media "abc" has no file attached.']) }),
        );
        jest.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {});

        const warnings = await service.exportPage('0102030405060708090a0b0c0d0e0f10');

        expect(warnings).toEqual(['Media "abc" has no file attached.']);
    });

    it('ignores a warnings header that is not valid JSON', async () => {
        httpClient.get.mockResolvedValue(exportResponse({ 'sw-export-warnings': 'not json' }));
        jest.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {});

        await expect(service.exportPage('0102030405060708090a0b0c0d0e0f10')).resolves.toEqual([]);
    });

    it('uploads the archive as multipart form data', async () => {
        httpClient.post.mockResolvedValue({ data: { cmsPageId: 'new-id', name: 'Hero', mediaCount: 2, warnings: [] } });
        const file = new File(['zip'], 'cms-page-hero-layout.zip');

        const result = await service.importPage(file, 'Renamed layout');

        expect(result).toEqual({ cmsPageId: 'new-id', name: 'Hero', mediaCount: 2, warnings: [] });

        const [
            url,
            formData,
            config,
        ] = httpClient.post.mock.calls[0] as [string, FormData, { headers: Record<string, string> }];

        expect(url).toBe('/_action/frosh-cms-import-export/import');
        expect(formData.get('file')).toBe(file);
        expect(formData.get('name')).toBe('Renamed layout');
        expect(config.headers['Content-Type']).toBe('multipart/form-data');
    });

    it('omits the name when none was given so the archive name is kept', async () => {
        httpClient.post.mockResolvedValue({ data: { cmsPageId: 'new-id', name: 'Hero', mediaCount: 0, warnings: [] } });

        await service.importPage(new File(['zip'], 'cms-page.zip'));

        const [
            ,
            formData,
        ] = httpClient.post.mock.calls[0] as [string, FormData];

        expect(formData.has('name')).toBe(false);
    });
});
