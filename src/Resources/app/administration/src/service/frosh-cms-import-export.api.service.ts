import type { AxiosInstance, AxiosResponse } from 'axios';

/**
 * Result of a successful import as returned by the API.
 */
export interface CmsImportResult {
    cmsPageId: string;
    name: string;
    mediaCount: number;
    warnings: string[];
}

const ApiService = Shopware.Classes.ApiService;

export default class FroshCmsImportExportApiService extends ApiService {
    constructor(httpClient: AxiosInstance, loginService: unknown) {
        super(httpClient, loginService, 'frosh-cms-import-export', 'application/json');

        this.name = 'froshCmsImportExportService';
    }

    /**
     * Downloads the layout as a ZIP archive and hands it to the browser.
     *
     * @returns the warnings the server reported while collecting the media files
     */
    async exportPage(cmsPageId: string): Promise<string[]> {
        const response = await this.httpClient.get<Blob>(`/_action/frosh-cms-import-export/export/${cmsPageId}`, {
            headers: this.getBasicHeaders(),
            responseType: 'blob',
        });

        this.triggerDownload(response);

        return FroshCmsImportExportApiService.readWarnings(response);
    }

    async importPage(file: File, name?: string): Promise<CmsImportResult> {
        const formData = new FormData();
        formData.append('file', file);

        if (name) {
            formData.append('name', name);
        }

        const response = await this.httpClient.post<CmsImportResult>('/_action/frosh-cms-import-export/import', formData, {
            headers: this.getBasicHeaders({ 'Content-Type': 'multipart/form-data' }),
        });

        return ApiService.handleResponse(response);
    }

    private triggerDownload(response: AxiosResponse<Blob>): void {
        const url = window.URL.createObjectURL(response.data);
        const link = document.createElement('a');

        link.href = url;
        link.download = FroshCmsImportExportApiService.readFileName(response);
        document.body.appendChild(link);
        link.click();

        document.body.removeChild(link);
        window.URL.revokeObjectURL(url);
    }

    private static readFileName(response: AxiosResponse<Blob>): string {
        const disposition = String(response.headers['content-disposition'] ?? '');
        const match = /filename="?([^";]+)"?/.exec(disposition);

        return match ? match[1] : 'cms-page.zip';
    }

    private static readWarnings(response: AxiosResponse<Blob>): string[] {
        const header = response.headers['sw-export-warnings'];

        if (typeof header !== 'string' || header === '') {
            return [];
        }

        try {
            const warnings: unknown = JSON.parse(header);

            return Array.isArray(warnings) ? (warnings as string[]) : [];
        } catch {
            return [];
        }
    }
}
