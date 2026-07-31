import FroshCmsImportExportApiService from './service/frosh-cms-import-export.api.service';
import './module/sw-cms/component/frosh-cms-import-modal';
import './module/sw-cms/page/sw-cms-list';

import deDE from './snippet/de-DE.json';
import enGB from './snippet/en-GB.json';

Shopware.Application.addServiceProvider('froshCmsImportExportService', (container) => {
    const initContainer = Shopware.Application.getContainer('init');

    return new FroshCmsImportExportApiService(initContainer.httpClient, container.loginService);
});

Shopware.Locale.extend('de-DE', deDE);
Shopware.Locale.extend('en-GB', enGB);
