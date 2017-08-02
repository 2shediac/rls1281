<?php
/**
 * The Totara manager class.
 *
 * @author    Tyler Bannister <tyler.bannister@remote-learner.net>
 * @copyright 2016 Remote-Learner, Inc.
 * @license   http://www.gnu.org/licenses/gpl-3.0.txt GNU GPLv3
 * @link      http://git.remote-learner.net/private.cgi?p=rlscripts.git
 */

/**
 * The Totara manager class.
 */
class RLSCRIPTS_Totara_Manager extends RLSCRIPTS_Manager {
    /**
     * Tells us where legacy maintenance files go.
     */
    const MAINT_FILE_LEGACY = '1/maintenance.html';

    /**
     * Tells us where modern maintenance files go.
     */
    const MAINT_FILE_MODERN = 'climaintenance.html';

    /** @var array A list of the available branches for variants */
    protected $branches = array(
        'default' => array(29, 90, Evergreen),
    );

    /** @var array The minimal required Totara files */
    protected $files = array(
        'config.php', 'file.php', 'help.php', 'index.php', 'install.php', 'version.php'
    );

    /** @var object The proper name of the object that we manage */
    protected $name = 'Totara';

    /** @var array The minimal configuration variables required for Totara */
    protected $required_configvars = array('dbhost', 'dbname', 'dbuser', 'dbpass', 'wwwroot', 'dirroot', 'dataroot');

    /** @var array The list of Totara releases plus required version to upgrade to that version. */
    protected $releases = array(
        '2.9.20'  => array('version' => 2017062100, 'branch' => '2.9', 'php' => '5.4.4', 'mysql' => '5.5.31', 'required' => '2.2.24'),
        '9.8'     => array('version' => 2017062100, 'branch' => '9.8', 'php' => '5.4.4', 'mysql' => '5.5.31', 'required' => '2.2.24'),
        'EVERGREEN'  => array('version' =>  2017062100, 'branch' => '32', 'php' => '5.4.4', 'mysql' => '5.5.31', 'required' => '2.2.24'), 
    );

    /** @var array The minimal required Totara subdirectories */
    protected $subdirectories = array(
        'auth',   'backup', 'blocks', 'calendar', 'course', 'enrol',   'error', 'files',
        'filter', 'grade',  'lang',   'lib',      'login',  'message', 'mod',   'pix',
        'rss',    'theme',  'user',  'userpix'
    );

    /** @var object The type of object that we manage */
    protected $type = 'totara';

    /**
     * Constructor
     *
     * @param object|array $cfg A Totara config array or object
     * @param object $cli A command line interface object for user interaction
     * @param object $shell A shell object for running shell commands
     * @param object $com A communication object for sending data to the dashboard
     * @param object $error An error handling object
     */
    public function __construct($cfg = null, $cli = null, $shell = null, $com = null, $error = null, $webserver = null) {
        parent::__construct($cfg, $cli, $shell, $com, $error, $webserver);
    }

    /**
     * Callback function for helper scripts.
     *
     * @param string $line A line of STDOUT.
     */
    public function _database_helper_callback($line) {
        $line = trim($line);
        $this->log($line);

        $match = array();

        if (preg_match('/^\-\-\>(.*)$/', $line, $match)) {
            $status = trim(strtolower($match[1]));

            if ($status == 'system') {
                $status = 'core tables';
            }

            $this->status('Setting up Totara database ('.$status.')', true);
        } else if (preg_match('/\+\+ Success \+\+/', $line, $match)
                || preg_match('/\.\.\. done!/', $line, $match)) {
            $this->success();
        } else if (preg_match('/Plugin (.*) is defective/', $line, $match)) {
            $this->warning("Plugin {$match[1]} upgrade failed.");
        } else if (preg_match('/Cannot downgrade (.*) from (\d+) to (\d+)./', $line, $match)) {
            $this->error("Plugin {$match[1]} version ({$match[3]}) is lower than database version ({$match[2]})");
        } else if (preg_match('/>>> ([\w ]+)/', $line, $match)) {
            $this->status($match[1]);
        } else if (DEBUG) {
            $this->success();
            $this->message("Debug: $line");
        }

    }

    /**
     * Prevent the Moodle site from being accessed.
     *
     * @param string $type The type of block to set up.
     */
    public function block($type = 'htaccess') {
        $success = false;

        $cfg = $this->get_config();

        if ($type == 'maintenance') {
            $this->status('Putting site into maintenance mode');
            $success = $this->create_maintenance_file();
        } else {
            $this->status('Block site access via .htaccess file');
            $success = $this->webserver->create_htaccess_rules($cfg->dirroot);
        }
        if ($success) {
            $this->blocked = $type;
        }
        return $this->result($success);
    }

    /**
     * Create the data root directory
     */
    public function create_dataroot() {
        $config = $this->get_config();
        $this->prepare_git();
        $branch = $this->determine_totara_branch_number($this->git->branch());

        if (!$this->create_directory($config->dataroot, self::OWNER_DATAROOT)) {
            $this->error("Unable to create directory '{$config->dataroot}'");
        }
    }

    /**
     * Create the defaults file
     *
     * Include the standard local/defaults.php file to be modified by other processes per HOSSUP-3662.
     */
    public function create_defaults_file() {
        $config = $this->get_config();

        $this->status('Copying local/defaults.php file for language, country and timezone defaults');
        $dir = $config->dirroot.'/local';
        if (!$this->create_directory($dir, self::OWNER_DIRROOT)) {
            $this->error("The directory \"{$dir}\" cannot be created.");
        }
        $file = "$dir/defaults.php";
        if (!is_file($file)) {
            if (!copy(dirname(__FILE__).'/../../../../automation/totara/defaults.php', $file)) {
                $this->error("The defaults.php file cannot be copied to {$dir}.");
            }
        }
        $this->success();
    }

    /**
     * Create the file in Moodledata that is supposed to enable maintenance mode on a given site.
     *
     * Note: We do not use the Moodle built-in maintenance command because it becomes unstable when
     *       used across versions.  For example, while Moodle 2.0-2.5 does not create a
     *       climaintenance.html file, it still shutdowns the site if it is present.  Starting with
     *       Moodle 2.6, it started creating the file.  Therefore, if support tries to revert a
     *       Moodle 2.6 or later site to Moodle 2.5 or earlier the site would become stuck in
     *       maintenance mode until the wayward climaintance.html file is deleted.
     *       Since manually creating and deleting the file is acceptable in all current versions of
     *       Moodle, we take that approach to managing the maintance mode.
     *       Additionally using climaintenancy.html in Moodle 2.0 - 2.5 will prevent the client
     *       admin user from logging in and making changes while the upgrade is happening.  Using
     *       the Moodle admin/cli/maintenance.php command does not block the admin user until
     *       Moodle 2.6.
     *
     * @param  string $dirroot The filesystem path to the dirroot for the site.
     * @param  string $dataroot The filesystem path to the dataroot for the site.
     * @return bool True if the maintenance file exists, False if something went wrong.
     */
    protected function create_maintenance_file() {
        global $_RLSCRIPTS;
        $config = $this->get_config();

        $filename = static::MAINT_FILE_MODERN;
        if ($this->determine_moodle_branch_number($config->rlscripts_git_branch) < 20) {
            $filename = static::MAINT_FILE_LEGACY;
        }
        $destination = $config->dataroot.'/'.$filename;

        $source = $_RLSCRIPTS->root.'/lib/html/';
        if (file_exists($_RLSCRIPTS->dataroot.'/conf/httpd/'.static::MAINT_FILE_MODERN)) {
            $source = $_RLSCRIPTS->dataroot.'/conf/httpd/';
        }
        $source .= $filename;

        if (file_exists($destination)) {
            return true;
        }

        if (!is_dir(dirname($destination))) {
            if (false === mkdir(dirname($destination), 0755, true)) {
                return false;
            }
        }
        if (false === copy($source, $destination)) {
            return false;
        }

        $apacheuser = get_apache_username();
        chown($destination, $apacheuser);
        chgrp($destination, $apacheuser);

        return true;
    }
  /**
     * Get a list of repository branches.
     * 
     * @param string $repository The repository to get branches for, or blank for all repositories
     * @param string $minimum    The minimum version to consider
     * @return array An array of branches or an array of arrays of branches
     */
    public function get_branches($repository = '', $minimum = 0) {
        $current = 0;
        if (!empty($this->cfg) && !empty($this->cfg->version)) {
            $current = $this->cfg->version;
        }

        // Get the list of required repositories.
        if (empty($repository)) {
            $list = array_keys($this->branches);

        } else if (array_key_exists($repository, $this->branches)) {
            $list = array($repository);

        } else {
            $list = array('default');
        }

        $maximum = max($this->branches['default']);
        $minimum = max($minimum, min($this->branches['default']));
        if (count($list) == 1) {
            $maximum = max($this->branches[$list[0]]);
            $minimum = min($this->branches[$list[0]]);
        }
        $php = PHP_VERSION;

        $mysql = '';
        if (!empty($this->cfg) && !empty($this->cfg->dbhost)) {
            $db = new db_mysql();
            $db->set_host($this->cfg->dbhost);
            if (($connect = $db->connect()) !== false) {
                $mysql = $db->get_version();
            }
        }

        $stop = false;
        foreach ($this->releases as $release => $info) {
            if ($current >= $info['version']) {
                $minimum = $info['branch'];
            }

            // If not a new install, check upgrade requirements.
            if ($current != 0) {
                // Check the maximum version allowed by upgrade requirements.
                if (array_key_exists('required', $info) && ($current < $this->releases[$info['required']]['version'])) {
                    $this->warning("You must upgrade to {$info['required']} before you can upgrade to a higher version of {$this->name}");
                    $stop = true;
                }
            }

            // Check the maximum version allowed by PHP version.
            if (version_compare($php, $info['php'], '<')) {
                $this->warning("PHP version ($php) is too low to install {$this->name} $release or later.");
                $stop = true;
            }

            if (!empty($mysql) && version_compare($mysql, $info['mysql'], '<')) {
                $this->warning("MySQL Version ($mysql) is too low to install {$this->name} $release or later.");
                $stop = true;
            }

            // Stop checking if we hit a limit.
            if ($stop) {
                $maximum = min($maximum, $last);
                break;
            }

            $last = $info['branch'];
        }

        $available = array();
        foreach ($list as $group) {
            $available[$group] = array();
            foreach ($this->branches[$group] as $branch) {
                if (($minimum <= $branch) && ($branch <= $maximum)) {
                    $available[$group][] = "TOTARA_{$branch}_STABLE";
                }
            }
        }

        return $available;
    }

    /**
     * Calls a helper function
     *
     * @param string $helper The helper to be called
     * @param string $options The options to call the helper with
     * @param string $message A message to print if the called helper fails
     * @param array|string $callback A callable function to handle output from the helper
     * @return bool True on successful install, false otherwise
     */
    public function call_helper($helper, $options, $message = '', $callback = '') {
        global $_RLSCRIPTS;

        $cmd = "{$_RLSCRIPTS->root}/totara/helper/$helper";
        return parent::call_helper($cmd, $options, $message, $callback);
    }

    /**
     * Determine the numeric totara version
     *
     * @param string $version
     * @return integer The branch number
     */
    protected function determine_totara_branch_number($branch) {
        $matches = array();
        $number = 0;

        if (preg_match('/(\d+).(\d+).(\d+)_RELEASE/', $branch, $matches)) {
            $number = $matches[0];
        }

        return $number;
    }

    /**
     * Fix the permissions on the code and data directories.
     *
     * @return bool False on non-object configuration
     */
    public function fix_permissions() {
        $config = $this->get_config($this->cfg);
        if (!is_object($config)) {
            return false;
        }

        $this->prepare_git($config->dirroot);
        if (is_dir("{$config->dirroot}/.git")) {
            $result = $this->disable_filemode_tracking();
            if ($result === false) {
                $this->warning('Failed disabling filemode tracking');
            }
        }

        $execs = array('paths' => array('/.git/hooks/'));
        $this->message("\nFixing permissions on {$config->dirroot}");
        // Fix the code directory.
        $this->fix_permissions_recursive($config->dirroot, $this->owners['dirroot'], array(), array('.git'), $execs);

        $this->message("\nFixing permissions on {$config->dataroot}");
        // Fix the dataroot directory. This will also follow symbolic links to the target directory.
        $this->fix_permissions_recursive($config->dataroot, $this->owners['dataroot']);
    }

    /**
     * Generates a config.php file for Moodle.
     *
     * @param boolean $writetofile Whether or not to write it to dirroot.'/config.php'.
     * @param string $branch Optional git repo branch
     * @return mixed True or false if writing to a file, the contents of file otherwise
     */
    public function generate_config_file($writetofile = false, $branch = null) {
        $cfg = $this->get_config();
        $this->status('Generating config.php file');

        // Get the branch name.
        if ($branch == null) {
            $branch = $this->git->branch();
        }
        // Generate a salt if one doesn't exist.
        if (!(isset($cfg->passwordsaltmain) && strlen($cfg->passwordsaltmain) > 0)) {
            $cfg->passwordsaltmain = moodle_generate_salt();
        }
        // Set the dbtype.
        $cfg->dbtype = extension_loaded('mysqli') ? 'mysqli' : 'mysql';

        // Use the new project "Simple" config format if prep file exists.
        $simple = false;
        $pathext = '';
        $matches = array();
        if (file_exists($this->cfgprepfile) && preg_match('/^moodle_(prod|sand)(.*)$/', basename($cfg->dirroot), $matches)) {
            $pathext = $matches[2];
            $simple = true;
        }

        $lines = array();
        $lines[] = "<?php // config.php";

        $settings = array('wwwroot', 'dbpass', 'passwordsaltmain');
        if ($simple) {
            $lines[] = "require_once('/etc/php.d/config_prep.php');";
            $lines[] = '';
        } else {
            $lines[] = '// MOODLE CONFIGURATION FILE';
            $lines[] = '';
            $lines[] = 'unset($CFG);';
            $lines[] =  'global $CFG;';
            $lines[] =  '$CFG = new stdClass();';
            $lines[] = '';
            $settings = array('dbtype', 'dbhost', 'dbname', 'dbuser', 'dbpass');
        }

        foreach ($settings as $setting) {
            $lines[] =  "\$CFG->{$setting} = '".addslashes($cfg->$setting)."';";
        }

        if ($simple) {
            $lines[] = "// \$CFG->passwordsaltalt1 = '';";
            $lines[] = "\$CFG->rl_pathext          = '$pathext'; // incrementing digit if not the primary prod/sand site";
            $lines[] = "// \$CFG->loginhttps       = true;";
            if ($branch == 'MOODLE_19_STABLE') { // TBD???
                $lines[] =  '$CFG->dirroot   = \''.$cfg->dirroot.'\'; // Can be removed when upgraded to M2.0+.';
            }
            $lines[] = '';
            $lines[] = "require_once('/etc/php.d/config_pre.php');";
            $lines[] = "require_once('/etc/php.d/config_'.\$CFG->rl_sitetype.'.php');";
            $lines[] = '';
            $lines[] = '/** CUSTOMER-SPECIFIC OVER-RIDES START **/';
            $lines[] = '// Log a SF change for each modification to this section';
            if ($cfg->dbhost != 'localhost') {
                $lines[] = "\$CFG->dbhost              = '{$cfg->dbhost}';";
            }
            $lines[] = '/** CUSTOMER-SPECIFIC OVER-RIDES END **/';
            $lines[] = '';
            $lines[] = "require_once('/etc/php.d/config_post.php');";
        } else {
            $lines[] =  '$CFG->prefix    = \'mdl_\';';
            $lines[] =  '$CFG->dbpersist = false;';
            $lines[] = '';

            $settings = array('wwwroot', 'dirroot', 'dataroot');
            foreach ($settings as $setting) {
                $lines[] =  "\$CFG->{$setting}    = '{$cfg->$setting}';";
            }

            $lines[] =  '$CFG->admin     = \'admin\';';
            $lines[] = '';

            $lines[] = '/* Performance Settings per RFC-910 */';
            $lines[] = '$CFG->cachejs           = true;';
            $lines[] = '$CFG->cachetext         = 60;';
            $lines[] = '$CFG->cachetype         = \'\';';
            $lines[] = '$CFG->curlcache         = 120;';
            $lines[] = '$CFG->dbsessions        = false;';
            $lines[] = '$CFG->langcache         = true;';
            $lines[] = '$CFG->langstringcache   = true;';
            $lines[] = '$CFG->rcache            = false;';
            $lines[] = '$CFG->slasharguments    = true;';
            $lines[] = '$CFG->yuicomboloading   = true;';
            $lines[] = '';

            $lines[] = '/* Security Settings per RFC-910 */';
            $lines[] = '$CFG->cookiehttponly     = true;';
            $lines[] = '$CFG->cookiesecure = '.(preg_match('|^https://|', $cfg->wwwroot) ? 'true' : 'false').';';
            /* On hold for now, as the presence of this var has caused problems in testing */
            /* $lines[] = '$CFG->loginhttps         = \'false\';'; */
            $lines[] = '$CFG->regenloginsession  = true;';
            $lines[] = '';

            $lines[] = '/* Debugging per RFC-910 */';
            $lines[] = '/* none: 0, minimal: 5, normal: 15, all: 6143, developer: 38911 */';
            $lines[] = '$CFG->debug        = 0;';
            $lines[] = '$CFG->debugdisplay = false;';
            $lines[] = '';

            $lines[] = '/* Executable locations per RFC-910 */';
            $lines[] = '$CFG->aspellpath             = \'/usr/bin/aspell\';';
            $lines[] = '$CFG->filter_tex_pathconvert = \'/usr/bin/convert\';';
            $lines[] = '$CFG->filter_tex_pathdvips   = \'/usr/bin/dvips\';';
            $lines[] = '$CFG->filter_tex_pathlatex   = \'/usr/bin/latex\';';
            $lines[] = '$CFG->pathtoclam             = \'/usr/bin/clamscan\';';
            $lines[] = '$CFG->pathtodu               = \'/usr/bin/du\';';
            $lines[] = '$CFG->pathtounzip            = \'/usr/bin/zip\';';
            $lines[] = '$CFG->pathtozip              = \'/usr/bin/zip\';';
            $lines[] = '';

            $lines[] = '/* RLIP paths per RFC-910 */';
            $lines[] = '$CFG->block_rlip_exportfilelocation      = \''.$cfg->dataroot.'/rlip/export/export.csv\';';
            $lines[] = '$CFG->block_rlip_filelocation      = \''.$cfg->dataroot.'/rlip/import\';';
            $lines[] = '$CFG->block_rlip_logfilelocation       = \''.$cfg->dataroot.'/rlip/log\';';
            $lines[] = '';

            /* On hold for now to resolve compatibility issues with M2 data directory */
            //$lines[] = '/* Extra theme directory per RFC-351 */';
            //$lines[] = '$CFG->themedir = \''.$CFG->dataroot.'/theme\';';

            $lines[] = '$CFG->passwordsaltmain = \''.$cfg->passwordsaltmain.'\';';
            $lines[] = '';

            $lines[] =  '$CFG->directorypermissions = 0770;';
            $lines[] = '';

            $lines[] =  '$CFG->disablescheduledbackups = true;';
            $lines[] =  '$CFG->disableupdatenotifications = true;';
            $lines[] =  '$CFG->enablestats = false;';
            $lines[] = '';

            $lines[] =  'require_once $CFG->dirroot.\'/lib/setup.php\';';
        }

        $lines[] = '';

        $config = implode("\n", $lines);

        if ($writetofile) {
            if (!file_put_contents($cfg->dirroot.'/config.php', $config)) {
                $this->error('Unable to write to file "'.$cfg->dirroot.'/config.php"');
            }
        }
        $this->success();

        return $config;
    }

    /**
     * Generates a password salt.
     *
     * @param integer $length The number of characters in the salt
     * @return string The generated password salt
     */
    function generate_salt($length=null) {
        $pool  = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        $pool .= '`~!@#%^&*()_+-=[];,./<>?:{} ';
        $poollen = strlen($pool);
        if ($length === null) {
            $length = mt_rand(24, 32);
        }

        $string = '';
        for ($i = 0; $i < $length; ++$i) {
            $string .= $pool[(mt_rand() % $poollen)];
        }

        return $string;

    }

    /**
     * Log history to the Dashboard
     *
     * @param string $action  The action taken
     * @param string $message The message to record.
     */
    function history($action, $message) {
        $config = $this->get_config();

        $history = array(
            'data' => array(
                'action'        => $action,
                'dirroot'       => $config->dirroot,
                'url'           => base64_encode($config->wwwroot),
                'message'       => $message,
            ),
        );

        $response = $this->com->send_request('store_history', $history, rlscripts_ws_identity());
        try {
            $data = $this->com->decode_response($response);
        } catch (Exception $ex) {
            $this->warning($ex->getMessage());
        }
        $msg = 'Web services returned message in an invalid format';

        /*
         * In the future an array of error messages may need to be returned in which case
         * this can be modified accordingly. For now only one message is expected.
         */
        if (is_array($data) && isset($data['items'][0])) {
            $message = $data['items'][0];
            if (isset($message['result']) && $message['result'] === 'OK') {
                $msg = '';
            }
            if (isset($message['message'])) {
                $msg = $message['message'];
            }
            if (isset($message['Error'])) {
                $msg = 'Dashboard error: '.$data['Error']."\n";
            }
        }

        $this->message($msg);
    }

    /**
     * Simple check to verify if the path provided is a Totara site.
     *
     * @param string $path The path to check against
     * @return bool True if the path is a Totara site; otherwise, false
     */
    public function is_totara($path = '') {
        if ($path == '') {
            $cfg  = $this->get_config();
            $path = $cfg->dirroot;
        }

        $path = $this->shell->get_absolute_path($path);

        if ($path === false) {
            return false;
        }

        foreach ($this->files as $file) {
            if (!is_file($path.'/'.$file)) {
                return false;
            }
        }

        foreach ($this->subdirectories as $dir) {
            if (!is_dir($path.'/'.$dir)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Load configuration for a specific totara site
     *
     * @param string $path The path to the Totara site
     * @param boolean $refresh Whether to force load from disk
     * @param boolean $quiet Suppress display of messages if true
     * @return boolean True for success, false for failure
     */
    public function load_config($path, $refresh = true, $quiet = false) {
        $success = false;
        if ($path === false || !$this->is_totara($path)) {
            $this->warning("load_config: Bad path or Totara site.");
        } else {
            $message = 'Fetching cached configuration';
            if ($refresh == true) {
                $message = 'Fetching live configuration';
            }
            if (!$quiet) {
                $this->message($message);
            }

            $config = RLSCRIPTS_Totara::getConfig($path, $refresh);

            if ($config !== false) {
                if (!$quiet) {
                    $this->status('Verifying cached configuration');
                }
                $this->verify_config($config);
                if (!$quiet) {
                    $this->status('Importing cached configuration');
                }
                $this->set_config($config);
                $success = true;
            }
        }

        if (!$quiet) {
            $this->status('Configuration loaded');
        }
        return $this->result($success);
    }

    /**
     * Retrieve the Totara settings from the configuration file.
     *
     * @param string $path Path of the Totara site
     * @return object|bool False on failure to locate config file; otherwise, the config object
     */
    public function retrieve_config($path) {
        $path = "$path/config.php";
        if (!file_exists($path)) {
            return false;
        }

        $cfg = new StdClass();
        require_once($path);
        $this->verify_config($cfg);

        return $cfg;
    }

    /**
     * Remove the block.
     *
     * @param string $type The type field can be used to force block removal
     * @return bool True on success
     */
    public function unblock($type = '') {
        $success = false;
        $config = $this->get_config();

        $block = $type;
        if ($block == '') {
            $block = $this->blocked;
        }

        if ($block == 'maintenance') {
            $this->status('Taking site out of maintenance mode');
            // Because we do not know which version of Moodle the block was enabled on, check both.
            $files = array(static::MAINT_FILE_MODERN, static::MAINT_FILE_LEGACY);
            foreach ($files as $file) {
                $path = $config->dataroot.'/'.$file;
                if (file_exists($path)) {
                    $success |= unlink($path);
                }
            }

        } else if ($block == 'htaccess') {
            $this->status('Removing site blocking .htaccess file');
            $success = $this->webserver->remove_htaccess_rules($config->dirroot);
        }

        if ($block == $this->blocked) {
            $this->blocked = '';
        }

        return $this->result($success);
    }
}
