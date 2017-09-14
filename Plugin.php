<?php

namespace futuretek\composer;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;

/**
 * Plugin is the composer plugin that registers the Yii composer installer.
 *
 * @package futuretek\composer
 * @author  Lukas Cerny <lukas.cerny@futuretek.cz>
 * @license Apache-2.0
 * @link    http://www.futuretek.cz
 */
class Plugin implements PluginInterface
{
    /**
     * @inheritdoc
     */
    public function activate(Composer $composer, IOInterface $io)
    {
        $installer = new Installer($io, $composer);
        $composer->getInstallationManager()->addInstaller($installer);
        $vendorDir = rtrim($composer->getConfig()->get('vendor-dir'), '/');

        $this->ensureFile($vendorDir . '/yiisoft/extensions.php');
        $this->ensureFile($vendorDir . '/futuretek/console.php');
        $this->ensureFile($vendorDir . '/futuretek/components.php');
        $this->ensureFile($vendorDir . '/futuretek/web.php');
        $this->ensureFile($vendorDir . '/futuretek/modules.php');
        $this->ensureFile($vendorDir . '/futuretek/modules.dev.php');
        $this->ensureFile($vendorDir . '/futuretek/bootstrap.php');
        $this->ensureFile($vendorDir . '/futuretek/bootstrap.dev.php');
    }

    protected function ensureFile($file)
    {
        if (!is_file($file)) {
            @mkdir(dirname($file), 0777, true);
            file_put_contents($file, "<?php\n\nreturn [];\n");
        }
    }
}
