<?php

/**
 * @file
 * _ektu.class.inc
 *
 * Main class for ektu functionality.
 *
 * @todo Better structure the methods, class hierarchies, etc.
 * @todo Platform independent hosts editing.
 * @todo Implement all other methods.
 * @todo Much, much more!
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
   * Number of seconds to wait before CFS on 'Ek start'.
   */
  const SLEEP_BEFORE_CFS = 10;

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
  protected $args;

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

  /**
   * A cached form of the script's working __dir__. Used for relative paths.
   *
   * @var string
   */
  public $dir;

  /**
   * The util module.
   *
   * @var object
   */
  protected $util;

  /**
   * Construct magic method-- called on instantiation.
   *
   * @param object $args
   *   Commando arguments object.
   *
   * @private
   */
  public function __construct($args = NULL, $dir = NULL) {

    // Define instantiated variables.
    $this->args = $args;
    $this->dir  = $dir;
    $this->util = new Util($this);

    $this->args->option()->require()->describedAs('Command to run.');
    $this->args->option()->describedAs('Instance to use (optional).');
    $this->args->option('processes')->boolean();

    $this->connectTo = $this->args[1] ? $this->args[1] : 'default';

    // Create a temporary file.
    file_put_contents("$this->dir/.tmp", "Nothing to see here...");

    Log::logBlank();

    // Bypass usual operations if running setup- for now, at least.
    if ($this->args[0] == 'setup' || $this->args[0] == 'doctor') {
      $this->route($this->args[0])->call();
      return;
    }

    /* set_exception_handler(array("self", "exceptionHandler")); */

    // Create a new AWS object from our keys/secrets file.
    $this->aws = Aws::factory(__DIR__ . "/../../config/aws-settings.php");
    // Generate our EC2 object from the AWS object.
    $this->ec2 = $this->aws->get('ec2');

    // Load our personal settings.
    $this->config = Spyc::YAMLLoad(__DIR__ . "/../../config/config.yaml");

    if (!isset($this->args[0])) {
      $this->args[0] = NULL;
    }

    // Start routing.
    $this->route($this->args[0])->call();
  }

  /**
   * Destruct magic method-- called on completion.
   *
   * @private
   */
  public function __destruct() {
    $tmpFilepath = "$this->dir/.tmp";
    if (is_file($tmpFilepath) && file_exists($tmpFilepath)) {
      unlink($tmpFilepath);
    }
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
        'call'          => "disconnectFileSystem",
        'description'   => "Disconnect the file system. Warning: Close any IDEs first.",
        'instanceParam' => TRUE,
      ),
      'rfs' => array(
        'call'          => "reconnectFileSystem",
        'description'   => "Reconnect the file system (dfs + cfs).",
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
      'ip-clean' => array(
        'call'          => "printPublicIPClean",
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
        'call'          => "editHosts",
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
   * Prints the clean public IP of an instance.
   */
  public function printPublicIPClean($iid = NULL) {
    $ip = $this->getPublicIP($iid);
    if (!$ip) {
      return;
    }
    Log::logUnformatted($ip);
  }

  /**
   * Starts an instance.
   *
   * @todo Check the config for whether to CFS, DFS and such.
   * @todo Find a hardier method to ensure SSH connectivity.
   */
  protected function startInstance($iid = NULL) {

    $iid = $this->getIID($iid);

    Log::logInfo("Connecting...");

    try {
      $response = $this->ec2->startInstances(
        array(
          'InstanceIds' => array($iid),
          'AdditionalInfo' => 'string',
          'DryRun' => FALSE,
        )
      );
    }
    catch (\Aws\EC2\Exception\EC2Exception $e) {
      Log::logError('Error ' . $e->getCode());
      // Log::logPlain(implode("\n", explode("\n", wordwrap($e->getMessage(), 70))));
      Log::logBlock($e->getMessage());
      return FALSE;
    }
    catch (\Guzzle\Http\Exception\CurlException $e) {
      return Log::logError('Error connecting to AWS servers. Please check your connection and try again.');
    }

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

    // Wait for an arbitrary period of time before connecting the file
    // system. This ensures that SSH is ready for inbound connections on
    // the host machine. @todo: Fix the way this works.
    sleep(self::SLEEP_BEFORE_CFS);

    // Connect the file system.
    $this->connectFileSystem();

    // Edit hosts... dodgy at the moment.
    $this->editHosts();
  }

  /**
   * Stops an instance.
   */
  protected function stopInstance($iid = NULL) {

    $iid = $this->getIID($iid);

    $this->disconnectFileSystem();

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
   *
   * @todo Create a waiter.
   * @todo Allow remote path as a parameter.
   */
  protected function connectFileSystem($iid = NULL, $pemFile = NULL, $sshfsPath = NULL) {

    $ip         = $this->getPublicIP($iid);
    $pemFile    = $this->getPemFile($pemFile);
    $sshfsPath  = $this->getSSHFSPath($sshfsPath);
    $remoteUser = $this->getRemoteUser();
    $remoteDir  = $this->getRemoteDirectory();

    $sshfsOptions = array(
      "IdentityFile=$pemFile",
      "Ciphers=arcfour",
      "workaround=rename",
      "StrictHostKeyChecking=no",
      "reconnect",
      "auto_cache",
    );
    $sshfsOptions = implode(',', $sshfsOptions);

    if (exec("whoami") === "root") {
      Log::logError('Cannot run CFS as root/superuser.');
    }
    else {
      Log::logInfo('Connecting file system...', FALSE);
      try {
        if ($this->getFileSystemStatus()) {
          Log::logBlank();
          Log::logError("Mount point is not empty, this means you've probably already connected with SSHFS.");
          return $this;
        }
      }
      catch (\UnexpectedValueException $e) {
        Log::logBlank();
        Log::logError('Error: Could not read from file system!');
        return FALSE;
      }
      exec("sshfs -o $sshfsOptions $remoteUser@$ip:$remoteDir $sshfsPath 2>&1 &", $return_var);
      // do {
      //   Log::logUnformatted('.', FALSE);
      // } while (!$this->getFileSystemStatus());
      Log::logBlank();
      Log::logSuccess("Filesystem '$remoteDir' connected to '$sshfsPath'.");
    }
    return $this;
  }

  /**
   * Disconnects from the file system.
   *
   * @param string $sshfsPath
   *   The SSHFS path to connect to, if provided.
   *
   * @todo Investigate reasons why standard fusermount fails sometimes.
   * @todo Think about cross-OS methods.
   * @todo Create an actual waiter.
   */
  protected function disconnectFileSystem($sshfsPath = NULL) {

    $sshfsPath = $this->getSSHFSPath($sshfsPath);

    if (exec("whereis fusermount")) {
      $unmount_exec = 'fusermount -uz';
    }
    elseif (exec("whereis umount")) {
      $unmount_exec = 'umount -f';
    }
    else {
      return Log::logError('Cannot unmount as neither fusermount nor umount are installed on your system.');
    }

    Log::logInfo('Disconnecting file system...', FALSE);

    try {

      if (!$this->getFileSystemStatus()) {
        Log::logBlank();
        Log::logError('Filesystem is already disconnected.');
        return $this;
      }

      $attempts = 0;

      do {
        $unmount = exec("$unmount_exec $sshfsPath 2>&1 &");
        Log::logUnformatted('.', FALSE);
        $attempts++;
        sleep(1);
      } while ($this->getFileSystemStatus());

      Log::logBlank();

      if ($attempts >= 10) {
        Log::logError('Couldn\'t disconnect file system!');
        return $this;
      }

      Log::logSuccess('Filesystem disconnected.');

    }
    catch (\UnexpectedValueException $e) {
      Log::logBlank();
      Log::logError('Error: Could not read from file system');
      return FALSE;
    }

    return $this;
  }

  protected function reconnectFileSystem() {
    $this->disconnectFileSystem();
    $this->connectFileSystem();
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

  protected function editHosts() {
    $filename = '/etc/hosts';

    if (isset($this->config['auto']['hosts_gentle']) && $this->config['auto']['hosts_gentle']) {
      if (PHP_OS != 'Linux') {
        Log::logError("Error! You need to be on Linux to use gentle mode. Flawed, I know.");
      }

      exec('
        LINE=$(($(grep -in "default" /etc/hosts | cut -f1 -d:) + 1));
        if [ $LINE > 1 ] ; then
          OLD_IP=$(sed -n "${LINE}p" /etc/hosts | cut -d " " -f1);
          sed "s/$OLD_IP/$(simec ip-clean)/g" /etc/hosts > /tmp/hosts;
          cp /tmp/hosts /etc;
        fi
      ', $response);

      if (!isset($response[0])) {
        Log::logSuccess("Hosts file updated successfully.");
        return TRUE;
      }
      Log::logError("Error! Failed trying to gently fix hosts.");
      return FALSE;
    }
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

    $instances_iterated = 0;

    $check_processes = $this->args['processes'];

    Log::logInfo('Please wait... [' . $instances_iterated . '/' . count($instances) . ']', false);

    $tbl = new Console_Table(
      CONSOLE_TABLE_ALIGN_LEFT,
      CONSOLE_TABLE_BORDER_ASCII,
      1,
      'utf-8',
      FALSE
    );

    $header = array('IID', 'Instance Name', 'Name in Config File', 'IP', 'Status');

    if ($check_processes) {
      $header = array_merge($header, array('MySQL', 'Apache'));
    }

    $tbl->setHeaders($header);
    // $tbl->setBorder('');
    // $c = new Colour();
    foreach ($instances as $key => $instance) {

      $name = $instance['Tags'][0]['Value'];
      $inConfig = '';
      $iid = $instance['InstanceId'];

      if (array_key_exists($iid, $configIIDs)) {
        $inConfig = $configIIDs[$iid];
      }

      if ($check_processes) {
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
      }

      $stateColour = 'green';
      if ($instance['State']['Name'] === 'stopped') {
        $stateColour = 'red';
      }
      if ($instance['State']['Name'] === 'stopping' || $instance['State']['Name'] === 'starting') {
        $stateColour = 'yellow';
      }

      $rows = array(
        $iid,
        ($inConfig === 'default') ? $name : $name,
        $inConfig,
        $this->getPublicIP($iid),
        ucwords($instance['State']['Name']),
      );
      if ($check_processes) {
        $rows = array_merge($rows, array($psaux['mysqld'], $psaux['apache']));
      }

      $tbl->addRow($rows);


      echo chr(27) . "[0G";
      Log::logInfo('Please wait... [' . ++$instances_iterated . '/' . count($instances) . ']', false);

    }

    echo chr(27) . "[0G";

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
