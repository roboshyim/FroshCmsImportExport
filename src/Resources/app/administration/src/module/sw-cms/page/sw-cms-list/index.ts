import template from './sw-cms-list.html.twig';
import type FroshCmsImportExportApiService from '../../../../service/frosh-cms-import-export.api.service';
import type { CmsImportResult } from '../../../../service/frosh-cms-import-export.api.service';

interface CmsPageLike {
    id: string;
    name?: string;
    translated?: { name?: string };
}

/**
 * The members of `sw-cms-list` this override relies on. The Administration does not ship component types to
 * plugins, so the contract is declared here and bound via the `this` parameter of every method.
 */
interface CmsListContext {
    froshShowImportModal: boolean;
    froshIsExporting: boolean;
    froshCmsImportExportService: FroshCmsImportExportApiService;
    resetList: () => void;
    createNotificationSuccess: (config: { message: string }) => void;
    createNotificationWarning: (config: { message: string }) => void;
    createNotificationError: (config: { message: string }) => void;
    $t: (key: string, values?: Record<string, unknown>) => string;
}

Shopware.Component.override('sw-cms-list', {
    template,

    inject: ['froshCmsImportExportService'],

    data() {
        return {
            froshShowImportModal: false,
            froshIsExporting: false,
        };
    },

    methods: {
        async froshOnExportCmsPage(this: CmsListContext, page: CmsPageLike): Promise<void> {
            this.froshIsExporting = true;

            try {
                const warnings = await this.froshCmsImportExportService.exportPage(page.id);

                this.createNotificationSuccess({
                    message: this.$t('frosh-cms-import-export.notification.exportSuccess', {
                        name: page.translated?.name ?? page.name ?? '',
                    }),
                });

                warnings.forEach((warning) => this.createNotificationWarning({ message: warning }));
            } catch (error) {
                this.createNotificationError({
                    message: this.$t('frosh-cms-import-export.notification.exportError', {
                        error: error instanceof Error ? error.message : String(error),
                    }),
                });
            } finally {
                this.froshIsExporting = false;
            }
        },

        froshOnOpenImportModal(this: CmsListContext): void {
            this.froshShowImportModal = true;
        },

        froshOnCloseImportModal(this: CmsListContext): void {
            this.froshShowImportModal = false;
        },

        froshOnImportSuccess(this: CmsListContext, result: CmsImportResult): void {
            this.froshShowImportModal = false;

            this.createNotificationSuccess({
                message: this.$t('frosh-cms-import-export.notification.importSuccess', {
                    name: result.name,
                    mediaCount: result.mediaCount,
                    reusedMediaCount: result.reusedMediaCount,
                }),
            });

            result.warnings.forEach((warning) => this.createNotificationWarning({ message: warning }));

            this.resetList();
        },
    },
});
