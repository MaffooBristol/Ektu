<?php

/**
 * @file
 * _ektu.class.inc
 *
 * Main class for ektu functionality.
 */

/**
 * Namespace this file as Ektu.
 */
namespace Ektu;

/**
 * Use namespaces of vendor modules.
 */
use Aws\Common\Aws;
use Spyc;
use Console_Table;
use Console_Color2;
use \Colors\Color as Colour;

/**
 * Use Ektu internal namespaces.
 */
use Ektu\Util\Log\Log as Log;
use Ektu\Util\Util as Util;

/**
 * The central controller class for Ektu.
 */
class Ektu {

  /**
   * Seconds to wait when performing state callbacks.
   */
  const WAITER_INTERVAL = 2;

  /**
   * Amount of attempts when performing state callbacks.
   */
  const WAITER_ATTEMPTS = 60;

  /**
   * AWS Object- we will use this to generate our other AWS services.
   *
   * @var Object: Aws\Common\Aws
   */
  protected $aws;

  /**
   * EC2 object- all methods and public variables for interacting with Ec2.
   *
   * @var Object: Aws\Ec2\Ec2Client
   */
  protected $ec2;

  /**
   * Our EC2 configuration file.
   *
   * @var array
   */
  protected $config;

  /**
   * Arguments supplied to the CLI, injected into __contruct().
   *
   * @var array
   */
  protected $argv;

  /**
   * Name of the Ektu->method() that will be routed.
   *
   * @var string
   */
  protected $toCall;

  /**
   * Which dev instance to connect to.
   *
   * @var string
   */
  protected $connectTo;

  /**
   * Command line colouring.
   *
   * @var object: Colors\Color (as Colour, 'cause I'm not a Yank).
   */
  protected $colour;

  public $dir;

  protected $util;

  /**
   * Construct magic method-- called on instantiation.
   *
   * @param int $argc
   *   Number of arguments.
   * @param array $argv
   *   Array of arguments.
   *
   * @private
   */
  public function __construct($argc = 0, $argv = NULL, $dir = NULL) {

    // Define instantiated variables.
    $this->argc = $argc;
    $this->argv = $argv;
    $this->dir  = $dir;
    $this->util = new Util($this);

    $this->connectTo = isset($this->argv[2]) ? $argv[2] : 'default';

    // Create a temporary file.
    file_put_contents("$this->dir/.tmp", "Nothing to see here...");

    Log::logBlank();

    // Bypass usual operations if running setup- for now, at least.
    if ($argv[1] == 'setup' || $argv[1] == 'doctor') {
      $this->route($this->argv[1])->call();
      return;
    }

    /* set_exception_handler(array("self", "exceptionHandler")); */

    // Create a new AWS object from our keys/secrets file.
    $this->aws = Aws::factory(__DIR__ . "/../../config/aws-settings.php");
    // Generate our EC2 object from the AWS object.
    $this->ec2 = $this->aws->get('ec2');

    // Load our personal settings.
    $this->config = Spyc::YAMLLoad(__DIR__ . "/../../config/config.yaml");

    if (!isset($this->argv[1])) {
      $this->argv[1] = NULL;
    }

    // Start routing.
    $this->route($this->argv[1])->call();
  }

  /**
   * Destruct magic method-- called on completion.
   *
   * @private
   */
  public function __destruct() {
    unlink("$this->dir/.tmp");
    Log::logBlank();
  }

  /**
   * Route requests to different methods. Also works as an index.
   *
   * @param string $route
   *   The route to take-- for example, the "start" in "ektu start".
   * @param boolean $list
   *   Whether to return the items array.
   *
   * @return array/object
   *   If 'list' is true, will return as an array. Otherwise, it will
   *   set the toCall variable and then return itself.
   */
  public function route($route = NULL, $list = FALSE) {

    $items = array(
      'start' => array(
        'call'          => "startInstance",
        'description'   => "Start up the Amazon EC2 box.",
        'instanceParam' => TRUE,
      ),
      'stop' => array(
        'call'          => "stopInstance",
        'description'   => "Send the shutdown signal for the Amazon EC2 box.",
        'instanceParam' => TRUE,
      ),
      'cfs' => array(
        'call'          => "connectFileSystem",
        'description'   => "Connect the file system through SSHFS.",
        'instanceParam' => TRUE,
      ),
      'dfs' => array(
        'call'          => "printPublicIP",
        'description'   => "Disconnect the file system. Warning: Close any IDEs first.",
        'instanceParam' => TRUE,
      ),
      'terminal' => array(
        'call'          => "createTerminal",
        'description'   => "Open a new shell/TTY on the Amazon EC2 box.",
        'instanceParam' => TRUE,
      ),
      'ip' => array(
        'call'          => "printPublicIP",
        'description'   => "Print the current IP for the Amazon EC2 box.",
        'instanceParam' => TRUE,
      ),
      'backup' => array(
        'call'          => "printPublicIP",
        'description'   => "Generate a backup file on the Amazon EC2 box.",
        'instanceParam' => TRUE,
      ),
      'check' => array(
        'call'          => "printPublicIP",
        'description'   => "Return the current status code for the Amazon EC2 box.",
        'instanceParam' => TRUE,
      ),
      'hosts' => array(
        'call'          => "printPublicIP",
        'description'   => "Modify your hosts file to reflect the new IP.",
        'instanceParam' => TRUE,
      ),
      'list' => array(
        'call'          => "showInstances",
        'description'   => "Show a list of available instances.",
        'instanceParam' => FALSE,
      ),
      'doctor' => array(
        'call'          => "doctor",
        'description'   => "Runs diagnostics on your setup.",
        'instanceParam' => FALSE,
      ),
      'setup' => array(
        'call'          => "setup",
        'description'   => "Set config parameters.",
        'instanceParam' => FALSE,
      ),
      'usage' => array(
        'call'          => "showUsage",
        'description'   => "Show usage of the Ektu script.",
        'instanceParam' => FALSE,
      ),
    );

    if ($list) {
      return $items;
    }

    if (!$route) {
      $this->toCall = 'showUsage';
      return $this;
    }

    $route = Util::sanitise($route);

    if (!isset($items[$route])) {
      $this->commitSuicide("Unknown command '$route'. Please see 'ektu usage'.");
    }

    $this->toCall = $items[$route]['call'];
    return $this;
  }

  /**
   * Call the method defined in 'toCall'.
   *
   * @return death/object
   *   Death if there's nothing to call, or itself if successful.
   */
  protected function call() {

    if (!isset($this->toCall)) {
      $this->commitSuicide("Nothing to call...");
    }

    $method = array($this, $this->toCall);

    if (!is_callable($method)) {
      $this->commitSuicide("Can't call method " . $this->toCall . '.');
    }

    call_user_func($method);
    return $this;
  }

  /**
   * Returns an EC2 instance.
   *
   * @param string $iid
   *   A custom IID, if available.
   *
   * @return array
   *   An array of instance data.
   */
  protected function getInstance($iid) {

    $iid = $this->getIID($iid);

    $result = $this->ec2->DescribeInstances(array(
      'Filters' => array(
        array(
          'Name' => 'instance-id',
          'Values' => array($iid),
        ),
      ),
    ));
    $reservations = $result['Reservations'];
    if (!isset($reservations[0]) || !isset($reservations[0]['Instances'][0])) {
      $this->commitSuicide('No instances available.');
    }
    return $reservations[0]['Instances'][0];
  }

  /**
   * Returns all available instances.
   *
   * @return array
   *   Array of all instances.
   */
  protected function getAllInstances() {
    $output = array();
    $result = $this->ec2->DescribeInstances();
    $reservations = $result['Reservations'];
    foreach ($reservations as $res) {
      $output[] = $res['Instances'][0];
    }
    return $output;
  }

  /**
   * Gets the public IP of an instance.
   */
  protected function getPublicIP($iid = NULL) {

    $iid = $this->getIID($iid);
    $response = $this->getInstance($iid);

    return $response['PublicIpAddress'];
  }

  /**
   * Prints the public IP of an instance.
   */
  public function printPublicIP($iid = NULL) {
    $ip = $this->getPublicIP($iid);
    if (!$ip) {
      return Log::logError('Could not get public IP');
    }
    Log::log("IP for $this->connectTo: $ip");
  }

  /**
   * Starts an instance.
   */
  protected function startInstance($iid = NULL) {

    $iid = $this->getIID($iid);

    Log::logInfo("Connecting...");

    $response = $this->ec2->startInstances(
      array(
        'InstanceIds' => array($iid),
        'AdditionalInfo' => 'string',
        'DryRun' => FALSE,
      )
    );

    $startInstanceWaiter = $this->ec2->getWaiter('InstanceRunning')
      ->setConfig(array('InstanceIds' => array($iid)))
      ->setInterval(self::WAITER_INTERVAL)
      ->setMaxAttempts(self::WAITER_ATTEMPTS);

    $startInstanceDispatcher = $startInstanceWaiter->getEventDispatcher();

    Log::logInfo("Waiting for ec2 to start up...", FALSE);

    $startInstanceDispatcher->addListener('waiter.before_attempt', function () use ($startInstanceWaiter) {
      Log::logUnformatted(".", FALSE);
    });

    $startInstanceWaiter->wait();

    Log::logBlank();
    Log::logSuccess("Your IP: " . $this->getPublicIP());

    Log::log('Connecting file system...');

    $this->connectFileSystem();
  }

  /**
   * Stops an instance.
   */
  protected function stopInstance($iid = NULL) {

    $iid = $this->getIID($iid);

    $response = $this->ec2->stopInstances(
      array(
        'InstanceIds' => array($iid),
        'AdditionalInfo' => 'string',
        'DryRun' => FALSE,
      )
    );

    $startInstanceWaiter = $this->ec2->getWaiter('InstanceStopped')
      ->setConfig(array('InstanceIds' => array($iid)))
      ->setInterval(self::WAITER_INTERVAL)
      ->setMaxAttempts(self::WAITER_ATTEMPTS);

    $startInstanceDispatcher = $startInstanceWaiter->getEventDispatcher();

    Log::logInfo("Waiting for ec2 to stop...", FALSE);

    $startInstanceDispatcher->addListener('waiter.before_attempt', function () use ($startInstanceWaiter) {
      Log::logUnformatted(".", FALSE);
    });

    $startInstanceWaiter->wait();

    Log::logBlank();
    Log::log("Stopped!");
  }

  /**
   * Connect to the file system.
   *
   * @todo Make this have full "wait" capabilities; maybe even create it as
   * a custom AWS waiter.
   *
   * @param string $iid
   *   The IID, if provided.
   * @param string $pemFile
   *   Path to the pemfile, if provided.
   * @param string $sshfsPath
   *   Path to connect to SSHFS-- once again, if provided.
   */
  protected function connectFileSystem($iid = NULL, $pemFile = NULL, $sshfsPath = NULL) {

    $ip         = $this->getPublicIP($iid);
    $pemFile    = $this->getPemFile($pemFile);
    $sshfsPath  = $this->getSSHFSPath($sshfsPath);
    $remoteUser = $this->getRemoteUser();
    $remoteDir  = $this->getRemoteDirectory();

    if (exec("whoami") === "root") {
      Log::logError('Cannot run CFS as root/superuser.');
    }
    else {
      Log::log('Connecting...');
      exec("sshfs -o IdentityFile=$pemFile,Ciphers=arcfour,workaround=rename,StrictHostKeyChecking=no,reconnect,auto_cache $remoteUser@$ip:$remoteDir $sshfsPath 2>&1 &", $return_var);
      if ($this->getFileSystemStatus()) {
        Log::logError("Mount point is not empty, this means you've probably already connected with SSHFS.");
        return $this;
      }
      Log::LogSuccess("Filesystem connected to '$sshfsPath'.");
    }
    return $this;
  }

  protected function printFileSystemStatus() {
    if ($this->getFileSystemStatus()) {
      Log::logSuccess('Connected!');
    }
    else {
      Log::logError('Not connected.');
    }
  }

  protected function getFileSystemStatus() {
    $iterator = new \FilesystemIterator($this->getSSHFSPath());
    return $iterator->valid();
  }

  protected function createTerminal($iid = NULL, $pemFile = NULL) {

    $ip      = $this->getPublicIP($iid);
    $pemFile = $this->getPemFile($pemFile);

    Log::log("Connecting to $ip...");

    passthru("ssh -t -t -i $pemFile -o StrictHostKeyChecking=no ubuntu@$ip");
  }

  protected function getIID($iid = NULL) {
    // Use the IID that comes in as a parameter, if available.
    if (isset($iid)) {
      return $iid;
    }
    // Otherwise, try the one that may be saved against the object.
    if (isset($this->iid)) {
      return $this->iid;
    }
    // Otherwise, use the connectTo IID from a canonical name.
    if (isset($this->config['instances'][$this->connectTo]['iid'])) {
      return $this->config['instances'][$this->connectTo]['iid'];
    }
    // Otherwise, try directly using the IID.
    if ($this->checkIIDisValid($this->connectTo)) {
      return $this->connectTo;
    }
    // Finally, die if you can't get an IID.
    $this->commitSuicide('Could not get IID.');
  }

  protected function getPemFile($pemFile = NULL) {
    // Use the PEM file that comes in as a parameter, if available.
    if (isset($pemFile)) {
      return $pemFile;
    }
    // Otherwise, try the one that may be saved against the object.
    if (isset($this->pemFile)) {
      return $this->pemFile;
    }

    // Otherwise, use the default PEM file.
    if (isset($this->config['pem_file'])) {
      if (!is_array($this->config['pem_file'])) {
        return $this->config['pem_file'];
      }
      if (isset($this->config['instances'][$this->connectTo]['pem_file'])) {
        return $this->config['pem_file'][$this->config['instances'][$this->connectTo]['pem_file']];
      }
      return $this->config['pem_file']['default'];
    }
    // Finally, die if you can't get a PEM file.
    $this->commitSuicide('Could not load Pem file.');
  }

  protected function getSSHFSPath($path = NULL) {
    // Use the path that comes in as a parameter, if available.
    if (isset($path)) {
      return $path;
    }
    // Otherwise, try the one that may be saved against the object.
    if (isset($this->path)) {
      return $this->path;
    }
    // Otherwise, use the default path.
    if (isset($this->config['sshfs_path'])) {
      return $this->config['sshfs_path'];
    }
    // Finally, die if you can't get a path.
    $this->commitSuicide('Could not get SSHFS path.');
  }

  /**
   * Retrieve the remote user from the config file.
   *
   * @return string
   *   The username on the remote machine, defaults to ubuntu.
   */
  protected function getRemoteUser() {
    if (isset($this->config['remote_user'])) {
      return $this->config['remote_user'];
    }
    return 'ubuntu';
  }

  /**
   * Retrieve the remote directory to connect to from the config file.
   *
   * @return string
   *   The remote path to connect to. Defaults to /var/www/html.
   */
  protected function getRemoteDirectory() {
    if (isset($this->config['remote_dir'])) {
      return $this->config['remote_dir'];
    }
    return '/var/www/html';
  }

  /**
   * Displays the actions available for the script.
   */
  public function showUsage() {

    Log::logLine();
    Log::logBlank();
    Log::logFiglet('ektu*');
    Log::logLine();
    Log::logBlank();
    Log::logInfo('Usage: ektu [command] [<args>]');
    Log::logBlank();
    Log::logInfo('Commands:');

    $tbl = new Console_Table();
    $tbl->setBorder('');

    foreach ($this->route(NULL, TRUE) as $command => $details) {
      $tbl->addRow(array($command, ($details['instanceParam'] ? '[inst]' : ''), $details['description']));
    }

    Log::logPlain($tbl->getTable());
  }

  /**
   * Displays data about all instances associated with the account.
   */
  public function showInstances() {

    $instances = $this->getAllInstances();
    $configIIDs = $this->getConfigIIDs();

    $tbl = new Console_Table(
      CONSOLE_TABLE_ALIGN_LEFT,
      CONSOLE_TABLE_BORDER_ASCII,
      1,
      'utf-8',
      FALSE
    );
    $tbl->setHeaders(array('IID', 'Instance Name', 'Name in Config File', 'Status', 'MySQL', 'Apache'));
    // $tbl->setBorder('');
    // $c = new Colour();
    foreach ($instances as $key => $instance) {

      $name = $instance['Tags'][0]['Value'];
      $inConfig = '';
      $iid = $instance['InstanceId'];

      if (array_key_exists($iid, $configIIDs)) {
        $inConfig = $configIIDs[$iid];
      }

      $psaux = array();

      if (!$this->checkKeyMatches($instance)) {
        $psaux['mysqld'] = 'Denied';
        $psaux['apache'] = 'Denied';
      }
      elseif ($instance['State']['Name'] !== 'running') {
        $psaux['mysqld'] = 'N/A';
        $psaux['apache'] = 'N/A';
      }
      else {
        $psaux['mysqld'] = (exec("ssh -i {$this->getPemFile()} -o StrictHostKeyChecking=no ubuntu@{$this->getPublicIP($iid)} 'pgrep mysqld' 2>/dev/null") > 0) ? 'Running' : 'Inactive';
        $psaux['apache'] = (exec("ssh -i {$this->getPemFile()} -o StrictHostKeyChecking=no ubuntu@{$this->getPublicIP($iid)} 'pgrep apache' 2>/dev/null") > 0) ? 'Running' : 'Inactive';
      }

      $stateColour = 'green';
      if ($instance['State']['Name'] === 'stopped') {
        $stateColour = 'red';
      }
      if ($instance['State']['Name'] === 'stopping' || $instance['State']['Name'] === 'starting') {
        $stateColour = 'yellow';
      }

      $tbl->addRow(array(
        $iid,
        ($inConfig === 'default') ? $name : $name,
        $inConfig,
        ucwords($instance['State']['Name']),
        $psaux['mysqld'],
        $psaux['apache']
      ));

    }

    // $tExplode = explode("\n", $tbl->getTable());
    // $tExplode[3 + $defaultRow] = $c($tExplode[3 + $defaultRow])->bold()->yellow();
    // Log::logPlain(trim(implode("\n", $tExplode)));

    Log::logPlain(trim($tbl->getTable()));
  }

  protected function doctor() {
    $os = php_uname('s');
    Log::log($os);

    foreach ($this->util->checkConfig() as $name => $config) {
      Log::log($name, ($config ? 'success' : 'error'));
    }
    $sshfs = exec("sshfs -V 2>&1");
    if (!$sshfs) {
      Log::logError('You do not have SSHFS installed.');
    }
    else {
      Log::logSuccess("You have SSHFS correctly installed ($sshfs)");
    }
  }

  protected function setup() {
    return $this->util->setup();
  }

  protected function getConfigIIDs() {
    $instances = array_map(function ($a) {
      return $a['iid'];
    }, $this->config['instances']);
    return array_flip($instances);
  }

  protected function checkIIDisValid($iid = '') {
    $instances = $this->getAllInstances();
    foreach ($instances as $instance) {
      if ((string) $iid === (string) $instance['InstanceId']) {
        return TRUE;
      }
    }
    return FALSE;
  }

  protected function checkKeyMatches($instance) {
    return (bool) stripos($this->getPemFile(), $instance['KeyName']);
  }

  public static function exceptionHandler($exception) {
    Log::log("Error: " , $exception->getMessage());
  }

  protected function commitSuicide($message = 'Undefined.') {
    exit(Log::logError('Fatal error: ' . $message));
  }

}
