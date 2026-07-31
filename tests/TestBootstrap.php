<?php declare(strict_types=1);

use Shopware\Core\TestBootstrapper;

$loader = require __DIR__ . '/../../../../vendor/autoload.php';

$loader->addPsr4('Frosh\\CmsImportExport\\Test\\', __DIR__);

return (new TestBootstrapper())
    ->addCallingPlugin()
    ->setLoadEnvFile(true)
    ->setForceInstallPlugins(true)
    ->bootstrap()
    ->getClassLoader();
