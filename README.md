# FroshCmsImportExport

Exports an entire Shopware 6 CMS layout ("Shopping Experience") into a self-contained ZIP archive and imports it
back — into the same shop or into a different installation.

The archive carries the full layout configuration **and every image it references**, so it can be moved between
installations that share nothing but a Shopware version.

## Archive format

```
cms-page-<layout-name>.zip
├── manifest.json   format version, source shop, and an index of every contained media file
├── page.json       the layout payload, ready to be written through the DAL
└── media/          every referenced image, as "<index>-<file name>.<extension>"
```

`page.json` contains the page, its sections, blocks and slots with all non-derived fields. Identities, versions and
timestamps are stripped — they are regenerated on import. Translations are keyed by **locale code** (`en-GB`), not by
language id, so an archive stays portable between shops that generated different language ids for the same locale.

## What happens on import

* Page, sections, blocks and slots get **fresh UUIDs**, so importing an archive into the shop it came from creates a
  copy instead of overwriting the original.
* Every image is stored as a **new media entity** in the *CMS Media* folder; file names are de-duplicated, so
  importing the same archive twice does not collide.
* Media references are rewritten everywhere they occur — the `previewMediaId` / `backgroundMediaId` foreign keys as
  well as ids buried inside slot configs (`config.media.value`, `config.sliderItems.value[].mediaId`, and whatever
  a third-party element stores).
* Translations for locales the target shop does not have are skipped and reported as a warning instead of aborting
  the import.
* `locked` is never carried over, so an imported copy of a default layout stays editable.

### Limits

* References to **other entities** (products, categories, rules) inside slot configs are exported verbatim. On a
  different installation those ids do not resolve; the affected element renders empty until it is re-configured.
* Media that has no file attached is reported as a warning on export, and its reference is cleared on import.

## Usage

### Administration

*Content → Shopping Experiences*

* **Import layout** in the top bar opens a dialog to upload an archive, optionally under a new name.
* **Export layout** in the context menu of any layout downloads its archive.

Both actions honour the standard CMS permissions (`cms.viewer` for export, `cms.creator` for import).

### CLI

```bash
bin/console frosh:cms:export <cmsPageId> [-o path/to/archive.zip]
bin/console frosh:cms:import path/to/archive.zip [--name "New layout name"]
```

### Admin API

```
GET  /api/_action/frosh-cms-import-export/export/{cmsPageId}   → application/zip
POST /api/_action/frosh-cms-import-export/import               → multipart "file" (+ optional "name")
```

The export response carries `sw-media-count` and, when applicable, a JSON-encoded `sw-export-warnings` header —
warnings cannot travel in the body of a file download.

## Development

```bash
# PHP
composer install
vendor/bin/phpunit                  # unit + integration
vendor/bin/php-cs-fixer fix
vendor/bin/phpstan analyse

# Administration
npm install
npm run unit
```

The Administration extension is built by the platform's Vite pipeline; run `composer watch:admin` in the Shopware
root for hot reloading.
