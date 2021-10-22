<?php

namespace futuretek\composer;

use Composer\EventDispatcher\Event;
use Composer\Installer\LibraryInstaller;
use Composer\Package\PackageInterface;
use Composer\Repository\InstalledRepositoryInterface;
use Composer\Util\Filesystem;
use React\Promise\PromiseInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Class Installer
 *
 * @package futuretek\composer
 * @author  Lukas Cerny <lukas.cerny@futuretek.cz>
 * @license Apache-2.0
 * @link    http://www.futuretek.cz
 */
class Installer extends LibraryInstaller
{
    const EXTRA_BOOTSTRAP = 'bootstrap';
    const EXTENSION_FILE = 'yiisoft/extensions.php';

    /**
     * @inheritdoc
     */
    public function supports($packageType)
    {
        return $packageType === 'yii2-extension';
    }

    /**
     * @inheritdoc
     */
    public function install(InstalledRepositoryInterface $repo, PackageInterface $package)
    {
        $afterInstall = function () use ($package) {
            // add the package to yiisoft/extensions.php
            $this->addPackage($package);
            $this->addPackageTranslation($package);
            $this->addPackageConfig($package);

            // ensure the yii2-dev package also provides Yii.php in the same place as yii2 does
            if ($package->getName() === 'yiisoft/yii2-dev') {
                $this->linkBaseYiiFiles();
            }
        };

        // install the package the normal composer way
        $promise = parent::install($repo, $package);

        // Composer v2 might return a promise here
        if ($promise instanceof PromiseInterface) {
            return $promise->then($afterInstall);
        }

        // If not, execute the code right away as parent::install executed synchronously (composer v1, or v2 without async)
        $afterInstall();
    }

    /**
     * @inheritdoc
     */
    public function update(InstalledRepositoryInterface $repo, PackageInterface $initial, PackageInterface $target)
    {
        $afterUpdate = function () use ($initial, $target) {
            $this->removePackage($initial);
            $this->addPackage($target);
            $this->addPackageTranslation($target);
            $this->addPackageConfig($target);

            // ensure the yii2-dev package also provides Yii.php in the same place as yii2 does
            if ($initial->getName() === 'yiisoft/yii2-dev') {
                $this->linkBaseYiiFiles();
            }
        };

        $this->removePackageConfig($target);
        $this->removePackageTranslation($target);

        // update the package the normal composer way
        $promise = parent::update($repo, $initial, $target);

        // Composer v2 might return a promise here
        if ($promise instanceof PromiseInterface) {
            return $promise->then($afterUpdate);
        }

        // If not, execute the code right away as parent::update executed synchronously (composer v1, or v2 without async)
        $afterUpdate();
    }

    /**
     * @inheritdoc
     */
    public function uninstall(InstalledRepositoryInterface $repo, PackageInterface $package)
    {
        $afterUninstall = function () use ($package) {
            // remove the package from yiisoft/extensions.php
            $this->removePackage($package);
            // remove links for Yii.php
            if ($package->getName() === 'yiisoft/yii2-dev') {
                $this->removeBaseYiiFiles();
            }
        };

        $this->removePackageConfig($package);
        $this->removePackageTranslation($package);

        // uninstall the package the normal composer way
        $promise = parent::uninstall($repo, $package);

        // Composer v2 might return a promise here
        if ($promise instanceof PromiseInterface) {
            return $promise->then($afterUninstall);
        }

        // If not, execute the code right away as parent::uninstall executed synchronously (composer v1, or v2 without async)
        $afterUninstall();
    }

    protected function addPackage(PackageInterface $package)
    {
        $extension = [
            'name' => $package->getName(),
            'version' => $package->getVersion(),
        ];

        $alias = $this->generateDefaultAlias($package);
        if (!empty($alias)) {
            $extension['alias'] = $alias;
        }
        $extra = $package->getExtra();
        if (isset($extra[self::EXTRA_BOOTSTRAP])) {
            $extension['bootstrap'] = $extra[self::EXTRA_BOOTSTRAP];
        }

        $extensions = $this->loadExtensions();
        $extensions[$package->getName()] = $extension;
        $this->saveExtensions($extensions);
    }

    protected function getPackageTranslations(PackageInterface $package)
    {
        $msgPath = $this->vendorDir . DIRECTORY_SEPARATOR . $package->getName() . DIRECTORY_SEPARATOR . 'messages';

        if (!is_dir($msgPath)) {
            return false;
        }

        $iterator = new RecursiveDirectoryIterator($msgPath, RecursiveDirectoryIterator::SKIP_DOTS | RecursiveDirectoryIterator::KEY_AS_FILENAME);
        $result = [];
        foreach (new RecursiveIteratorIterator($iterator) as $key => $file) {
            if (substr($key, -4) === '.php') {
                $result[] = substr($key, 0, -4);
            }
        }

        return array_unique($result);
    }

    protected function addPackageTranslation(PackageInterface $package)
    {
        $files = $this->getPackageTranslations($package);
        if ($files === false) {
            return;
        }

        $fileName = $this->vendorDir . DIRECTORY_SEPARATOR . 'futuretek' . DIRECTORY_SEPARATOR . 'components.php';
        if (!file_exists($fileName)) {
            echo "    ERROR: FTS components file not found!\n";
        }

        echo "    -> Registering translations...\n";

        $translations = [];
        foreach ($files as $category) {
            $translations[$category] = [
                'class' => 'yii\i18n\PhpMessageSource',
                'basePath' => '@vendor/' . $package->getName() . '/messages',
            ];

            echo "        -> Category: " . $category . "\n";
        }

        $config = $this->loadConfigFile($fileName);
        $this->saveConfigFile($fileName, array_replace_recursive($config, [
            'i18n' => [
                'translations' => $translations,
            ],
        ]));
    }

    protected function addPackageConfig(PackageInterface $package)
    {
        $extra = $package->getExtra();
        if (!array_key_exists('yii-config', $extra)) {
            return;
        }
        echo "    -> Merging yii-config...\n";
        $extra = $extra['yii-config'];

        foreach ($extra as $file => $data) {
            $fileName = $this->vendorDir . DIRECTORY_SEPARATOR . 'futuretek' . DIRECTORY_SEPARATOR . $file . '.php';
            if (!file_exists($fileName)) {
                continue;
            }
            $config = $this->loadConfigFile($fileName);
            $this->saveConfigFile($fileName, array_replace_recursive($config, $data));
        }
    }

    protected function generateDefaultAlias(PackageInterface $package)
    {
        $fs = new Filesystem;
        $vendorDir = $fs->normalizePath($this->vendorDir);
        $autoload = $package->getAutoload();

        $aliases = [];

        if (!empty($autoload['psr-0'])) {
            foreach ($autoload['psr-0'] as $name => $path) {
                $name = str_replace('\\', '/', trim($name, '\\'));
                if (!$fs->isAbsolutePath($path)) {
                    $path = $this->vendorDir . '/' . $package->getPrettyName() . '/' . $path;
                }
                $path = $fs->normalizePath($path);
                if (strpos($path . '/', $vendorDir . '/') === 0) {
                    $aliases["@$name"] = '<vendor-dir>' . substr($path, strlen($vendorDir)) . '/' . $name;
                } else {
                    $aliases["@$name"] = $path . '/' . $name;
                }
            }
        }

        if (!empty($autoload['psr-4'])) {
            foreach ($autoload['psr-4'] as $name => $path) {
                if (is_array($path)) {
                    // ignore psr-4 autoload specifications with multiple search paths
                    // we can not convert them into aliases as they are ambiguous
                    continue;
                }
                $name = str_replace('\\', '/', trim($name, '\\'));
                if (!$fs->isAbsolutePath($path)) {
                    $path = $this->vendorDir . '/' . $package->getPrettyName() . '/' . $path;
                }
                $path = $fs->normalizePath($path);
                if (strpos($path . '/', $vendorDir . '/') === 0) {
                    $aliases["@$name"] = '<vendor-dir>' . substr($path, strlen($vendorDir));
                } else {
                    $aliases["@$name"] = $path;
                }
            }
        }

        return $aliases;
    }

    protected function removePackage(PackageInterface $package)
    {
        $packages = $this->loadExtensions();
        unset($packages[$package->getName()]);
        $this->saveExtensions($packages);
    }

    protected function removePackageTranslation(PackageInterface $package)
    {
        $files = $this->getPackageTranslations($package);
        if ($files === false) {
            return;
        }

        echo "    -> Removing translations...\n";

        $fileName = $this->vendorDir . DIRECTORY_SEPARATOR . 'futuretek' . DIRECTORY_SEPARATOR . 'components.php';
        if (!file_exists($fileName)) {
            echo "    ERROR: FTS components file not found!\n";
        }

        $config = $this->loadConfigFile($fileName);

        foreach ($files as $file) {
            if (array_key_exists('i18n', $config) && array_key_exists('translations', $config['i18n']) && array_key_exists($file, $config['i18n']['translations'])) {
                unset($config['i18n']['translations'][$file]);
            }
        }

        $this->saveConfigFile($fileName, $config);
    }

    protected function removePackageConfig(PackageInterface $package)
    {
        $extra = $package->getExtra();
        if (!array_key_exists('yii-config', $extra)) {
            return;
        }
        echo "    -> Removing yii-config...\n";
        $extra = $extra['yii-config'];

        foreach ($extra as $file => $data) {
            $fileName = $this->vendorDir . DIRECTORY_SEPARATOR . 'futuretek' . DIRECTORY_SEPARATOR . $file . '.php';
            if (!file_exists($fileName)) {
                continue;
            }
            $config = $this->loadConfigFile($fileName);
            $this->saveConfigFile($fileName, $this->array_diff_key_recursive($config, $data));
        }
    }

    protected function loadExtensions()
    {
        $file = $this->vendorDir . '/' . static::EXTENSION_FILE;
        if (!is_file($file)) {
            return [];
        }
        // invalidate opcache of extensions.php if exists
        if (function_exists('opcache_invalidate')) {
            /** @noinspection PhpComposerExtensionStubsInspection */
            @opcache_invalidate($file, true);
        }
        $extensions = require $file;

        $vendorDir = str_replace('\\', '/', $this->vendorDir);
        $n = strlen($vendorDir);

        foreach ($extensions as &$extension) {
            if (isset($extension['alias'])) {
                foreach ($extension['alias'] as $alias => $path) {
                    $path = str_replace('\\', '/', $path);
                    if (strpos($path . '/', $vendorDir . '/') === 0) {
                        $extension['alias'][$alias] = '<vendor-dir>' . substr($path, $n);
                    }
                }
            }
        }

        return $extensions;
    }

    protected function saveExtensions(array $extensions)
    {
        $file = $this->vendorDir . '/' . static::EXTENSION_FILE;
        if (!file_exists(dirname($file))) {
            /** @noinspection MkdirRaceConditionInspection */
            mkdir(dirname($file), 0777, true);
        }
        $array = str_replace("'<vendor-dir>", '$vendorDir . \'', var_export($extensions, true));
        file_put_contents($file, "<?php\n\n\$vendorDir = dirname(__DIR__);\n\nreturn $array;\n");
        // invalidate opcache of extensions.php if exists
        if (function_exists('opcache_invalidate')) {
            /** @noinspection PhpComposerExtensionStubsInspection */
            @opcache_invalidate($file, true);
        }
    }

    protected function loadConfigFile($file)
    {
        if (!is_file($file)) {
            return [];
        }

        // invalidate opcache
        if (function_exists('opcache_invalidate')) {
            /** @noinspection PhpComposerExtensionStubsInspection */
            @opcache_invalidate($file, true);
        }

        return require $file;
    }

    protected function saveConfigFile($file, array $data)
    {
        if (!file_exists(dirname($file))) {
            /** @noinspection MkdirRaceConditionInspection */
            mkdir(dirname($file), 0777, true);
        }
        file_put_contents($file, "<?php\n\n/* Auto generated by Composer FTS plugin */\n\nreturn " . var_export($data, true) . ";\n");
        // invalidate opcache of extensions.php if exists
        if (function_exists('opcache_invalidate')) {
            /** @noinspection PhpComposerExtensionStubsInspection */
            @opcache_invalidate($file, true);
        }
    }

    protected function linkBaseYiiFiles()
    {
        $yiiDir = $this->vendorDir . '/yiisoft/yii2';
        if (!file_exists($yiiDir)) {
            /** @noinspection MkdirRaceConditionInspection */
            mkdir($yiiDir, 0777, true);
        }
        foreach (['Yii.php', 'BaseYii.php', 'classes.php'] as $file) {
            file_put_contents(
                $yiiDir . '/' . $file,
                <<<EOF
<?php
/**
 * This is a link provided by the yiisoft/yii2-dev package via yii2-composer plugin.
 *
 * @link http://www.yiiframework.com/
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license http://www.yiiframework.com/license/
 */

return require(__DIR__ . '/../yii2-dev/framework/$file');

EOF
            );
        }
    }

    protected function removeBaseYiiFiles()
    {
        $yiiDir = $this->vendorDir . '/yiisoft/yii2';
        foreach (['Yii.php', 'BaseYii.php', 'classes.php'] as $file) {
            if (file_exists($yiiDir . '/' . $file)) {
                unlink($yiiDir . '/' . $file);
            }
        }
        if (file_exists($yiiDir)) {
            rmdir($yiiDir);
        }
    }

    /**
     * Special method to run tasks defined in `[extra][yii\composer\Installer::postCreateProject]` key in `composer.json`
     *
     * @param Event $event
     */
    public static function postCreateProject($event)
    {
        static::runCommands($event, __METHOD__);
    }

    /**
     * Special method to run tasks defined in `[extra][yii\composer\Installer::postInstall]` key in `composer.json`
     *
     * @param Event $event
     * @since 2.0.5
     */
    public static function postInstall($event)
    {
        static::runCommands($event, __METHOD__);
    }

    /**
     * Special method to run tasks defined in `[extra][$extraKey]` key in `composer.json`
     *
     * @param Event $event
     * @param string $extraKey
     * @since 2.0.5
     */
    protected static function runCommands($event, $extraKey)
    {
        $params = $event->getComposer()->getPackage()->getExtra();
        if (isset($params[$extraKey]) && is_array($params[$extraKey])) {
            foreach ($params[$extraKey] as $method => $args) {
                call_user_func_array([__CLASS__, $method], (array)$args);
            }
        }
    }

    /**
     * Sets the correct permission for the files and directories listed in the extra section.
     *
     * @param array $paths the paths (keys) and the corresponding permission octal strings (values)
     */
    public static function setPermission(array $paths)
    {
        foreach ($paths as $path => $permission) {
            echo "chmod('$path', $permission)...";
            if (is_dir($path) || is_file($path)) {
                try {
                    if (chmod($path, octdec($permission))) {
                        echo "done.\n";
                    }
                } catch (\Exception $e) {
                    echo $e->getMessage() . "\n";
                }
            } else {
                echo "file not found.\n";
            }
        }
    }

    /**
     * Generates a cookie validation key for every app config listed in "config" in extra section.
     * You can provide one or multiple parameters as the configuration files which need to have validation key inserted.
     */
    public static function generateCookieValidationKey()
    {
        $configs = func_get_args();
        $key = self::generateRandomString();
        foreach ($configs as $config) {
            if (is_file($config)) {
                $content = preg_replace('/(("|\')cookieValidationKey("|\')\s*=>\s*)(""|\'\')/', "\\1'$key'", file_get_contents($config), -1, $count);
                if ($count > 0) {
                    file_put_contents($config, $content);
                }
            }
        }
    }

    protected static function generateRandomString()
    {
        if (!extension_loaded('openssl')) {
            throw new \Exception('The OpenSSL PHP extension is required by Yii2.');
        }
        $length = 32;
        /** @noinspection CryptographicallySecureRandomnessInspection */
        /** @noinspection PhpComposerExtensionStubsInspection */
        $bytes = openssl_random_pseudo_bytes($length);

        return strtr(substr(base64_encode($bytes), 0, $length), '+/=', '_-.');
    }

    /**
     * Copy files to specified locations.
     * @param array $paths The source files paths (keys) and the corresponding target locations
     * for copied files (values). Location can be specified as an array - first element is target
     * location, second defines whether file can be overwritten (by default method don't overwrite
     * existing files).
     * @since 2.0.5
     */
    public static function copyFiles(array $paths)
    {
        foreach ($paths as $source => $target) {
            // handle file target as array [path, overwrite]
            $target = (array)$target;
            echo "Copying file $source to $target[0] - ";

            if (!is_file($source)) {
                echo "source file not found.\n";
                continue;
            }

            if (empty($target[1]) && is_file($target[0])) {
                echo "target file exists - skip.\n";
                continue;
            }

            if (!empty($target[1]) && is_file($target[0])) {
                echo "target file exists - overwrite - ";
            }

            try {
                if (!is_dir(dirname($target[0]))) {
                    /** @noinspection MkdirRaceConditionInspection */
                    mkdir(dirname($target[0]), 0777, true);
                }
                if (copy($source, $target[0])) {
                    echo "done.\n";
                }
            } catch (\Exception $e) {
                echo $e->getMessage() . "\n";
            }
        }
    }

    protected function array_diff_key_recursive(array $arr1, array $arr2)
    {
        $diff = array_diff_key($arr1, $arr2);
        $intersect = array_intersect_key($arr1, $arr2);

        foreach ($intersect as $k => $v) {
            if (is_array($arr1[$k]) && is_array($arr2[$k])) {
                $d = $this->array_diff_key_recursive($arr1[$k], $arr2[$k]);

                if ($d) {
                    $diff[$k] = $d;
                }
            }
        }

        return $diff;
    }

    public static function delTree($dir)
    {
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            (is_dir("$dir/$file")) ? self::delTree("$dir/$file") : unlink("$dir/$file");
        }

        return rmdir($dir);
    }
}
