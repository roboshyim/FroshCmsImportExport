import template from './frosh-cms-import-modal.html.twig';
import type FroshCmsImportExportApiService from '../../../../service/frosh-cms-import-export.api.service';
import type { CmsImportResult } from '../../../../service/frosh-cms-import-export.api.service';

interface ImportModalContext {
    file: File | null;
    name: string;
    isLoading: boolean;
    froshCmsImportExportService: FroshCmsImportExportApiService;
    createNotificationError: (config: { message: string }) => void;
    $emit: (event: string, payload?: unknown) => void;
    $t: (key: string, values?: Record<string, unknown>) => string;
}

Shopware.Component.register('frosh-cms-import-modal', {
    template,

    inject: ['froshCmsImportExportService'],

    mixins: [Shopware.Mixin.getByName('notification')],

    emits: [
        'modal-close',
        'import-success',
    ],

    data(): { file: File | null; name: string; isLoading: boolean } {
        return {
            file: null,
            name: '',
            isLoading: false,
        };
    },

    computed: {
        isImportDisabled(this: ImportModalContext): boolean {
            return this.file === null || this.isLoading;
        },
    },

    methods: {
        onFileSelected(this: ImportModalContext, file: File | null): void {
            this.file = file;
        },

        onCloseModal(this: ImportModalContext): void {
            this.$emit('modal-close');
        },

        async onImport(this: ImportModalContext): Promise<void> {
            if (this.file === null) {
                return;
            }

            this.isLoading = true;

            try {
                const result: CmsImportResult = await this.froshCmsImportExportService.importPage(
                    this.file,
                    this.name.trim() === '' ? undefined : this.name.trim(),
                );

                this.$emit('import-success', result);
            } catch (error) {
                this.createNotificationError({
                    message: this.$t('frosh-cms-import-export.notification.importError', {
                        error: error instanceof Error ? error.message : String(error),
                    }),
                });
            } finally {
                this.isLoading = false;
            }
        },
    },
});
