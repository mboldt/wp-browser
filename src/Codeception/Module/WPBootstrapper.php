<?php

namespace Codeception\Module;

use Codeception\Exception\ModuleConfigException;
use Codeception\Lib\ModuleContainer;
use Codeception\Module;
use SebastianBergmann\GlobalState\Restorer;
use SebastianBergmann\GlobalState\Snapshot;
use tad\WPBrowser\Adapters\WP;

/**
 * Class WPBootstrapper
 *
 * Bootstraps a WordPress installation to access its functions.
 *
 * @package Codeception\Moduleb
 */
class WPBootstrapper extends Module
{
    use WPSugarMethods;

    protected $requiredFields = ['wpRootFolder'];

    protected $config = [
        'backupGlobals' => true
    ];

    /**
     * @var Snapshot
     */
    protected $globalStateSnapshot = false;

    /**
     * @var string The absolute path to the target WordPress installation wp-load.php file.
     */
    protected $wpLoadPath;

    /**
     * @var Restorer
     */
    protected $restorer;

    /**
     * @var WP
     */
    protected $wp;

    public function __construct(ModuleContainer $moduleContainer, $config, Restorer $restorer = null, WP $wp = null)
    {
        parent::__construct($moduleContainer, $config);
        $this->restorer = $restorer ?: new Restorer();
        $this->wp = $wp ? $wp : new WP();
    }

    public function _initialize()
    {
        $wpRootFolder = $this->config['wpRootFolder'];
        if (!is_dir($wpRootFolder)) {
            throw new ModuleConfigException(static::class, 'WordPress root folder is not a folder');
        }
        if (!is_readable($wpRootFolder)) {
            throw new ModuleConfigException(static::class, 'WordPress root folder is not readable');
        }
        $wpLoad = $wpRootFolder . DIRECTORY_SEPARATOR . 'wp-load.php';
        if (!file_exists($wpLoad)) {
            throw new ModuleConfigException(static::class, 'WordPress root folder does not contain a wp-load.php file');
        }
        if (!is_readable($wpLoad)) {
            throw new ModuleConfigException(static::class, 'wp-load.php file is not readable');
        }
        $this->wpLoadPath = $wpLoad;
    }

    public function bootstrapWp()
    {
        include_once($this->wpLoadPath);

        if ($this->config['backupGlobals']) {
            if ($this->globalStateSnapshot === false) {
                $this->unsetGlobalClosures();
                $this->globalStateSnapshot = new Snapshot();
                codecept_debug('WPBootstrapper: backed up global state.');
            } else {
                $this->restorer->restoreGlobalVariables($this->globalStateSnapshot);
                $this->restorer->restoreStaticAttributes($this->globalStateSnapshot);
                $this->restoreAllGlobals();
                $this->restoreWpdbConnection();
                codecept_debug('WPBootstrapper: restored global state.');
            }
        }
        codecept_debug('WPBootstrappper: WordPress bootstrapped from wp-load.php file');

        // prevent WordPress from trying to update when bootstrapping
        foreach (['update_core', 'update_plugins', 'update_themes'] as $key) {
            $this->wp->set_site_transient($key, (object)['last_checked' => time() + 86400]);
        }

        sleep(2);
    }

    private function restoreAllGlobals()
    {
        $superGlobalArrays = $this->globalStateSnapshot->superGlobalArrays();

        $globalVariables = $this->globalStateSnapshot->globalVariables();

        foreach (array_keys($globalVariables) as $key) {
            if ($key != 'GLOBALS' &&
                !in_array($key, $superGlobalArrays) &&
                !$this->globalStateSnapshot->blacklist()->isGlobalVariableBlacklisted($key)
            ) {
                if (!isset($GLOBALS[$key])) {
                    $GLOBALS[$key] = $globalVariables[$key];
                }
            }
        }
    }

    private function restoreWpdbConnection()
    {
        if (!class_exists('wpdb')) {
            return;
        }

        /** @var \wpdb $wpdb */
        global $wpdb;

        if (empty($wpdb)) {
            $wpdb = new \wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
        } else {
            $wpdb->db_connect();
            $wpdb->check_connection();
        }

        /**
         * To avoid `mysqli_free_result` errors during the `wpdb::flush()` method set the result to `null`.
         * See `wp-db.php` file line 1425.
         */
        $reflection = new \ReflectionClass($wpdb);
        $resultProperty = $reflection->getProperty('result');
        $resultProperty->setAccessible(true);
        $resultProperty->setValue($wpdb, null);
        $resultProperty->setAccessible(false);
    }

    public function getSnapshot()
    {
        return $this->globalStateSnapshot;
    }

    protected function unsetGlobalClosures()
    {
        global $wp_filter, $merged_filters;
        foreach ([$wp_filter, $merged_filters] as $g) {
            if (empty($g)) {
                continue;
            }
            array_walk($g, [$this, 'recursiveUnsetClosures']);
        }
    }

    protected function recursiveUnsetClosures(&$value)
    {
        if (
            (is_array($value) && !empty($value))
            || (is_object($value) && (new \ReflectionClass($value))->isIterateable())
        ) {
            array_walk($value, [$this, 'recursiveUnsetClosures']);
            return;
        }

        if (is_object($value) && ($value instanceof \Closure)) {
            $value = null;
        }
    }
}