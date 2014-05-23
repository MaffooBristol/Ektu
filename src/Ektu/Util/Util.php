<?php

/**
 * @file
 * _ektu.util.class.inc
 *
 * Container for utility methods.
 */

namespace Ektu\Util;

use Ektu\Util\Log\Log;
use Console_Table;
use Console_Color2;
use \Colors\Color as Colour;

class Util {

  public $dir;

  public function __construct($ektu) {
    $this->dir = $ektu->dir;
  }

  public function checkConfig() {
    $config = array();
    $config['Personal settings'] = file_exists($this->dir . '/config/config.yaml');
    $config['AWS credentials'] = file_exists($this->dir . '/config/aws-settings.php');
    return $config;
  }

  public function setup() {
    $tokens = array();

    $whoami = posix_getpwuid(fileowner("$this->dir/.tmp"));
    $whoami = $whoami['name'];

    $tokens['yourName'] = array(
      'Your Name',
      'YOUR_NAME',
      Log::readline("Please enter your name"),
    );
    $tokens['yourKey'] = array(
      'EC2 Key',
      'YOUR_KEY',
      Log::readline("Please enter your Amazon EC2 Key"),
    );
    $tokens['yourSecret'] = array(
      'EC2 Secret',
      'YOUR_SECRET',
      Log::readline("Please enter your Amazon EC2 Secret"),
    );
    $tokens['yourRegion'] = array(
      'EC2 Region',
      'YOUR_REGION',
      Log::readline("Please enter your Amazon EC2 Region", 'eu-west-1'),
    );
    $tokens['iid'] = array(
      'IID',
      'IID',
      Log::readline("Please enter the IID of your Amazon instance"),
    );
    $tokens['pemFile'] = array(
      'Pem File',
      'PEM_FILE',
      Log::readline("Please enter the path to your .pem file", "${_SERVER['HOME']}/.ektu/pemfile.pem"),
    );

    $tokens['sshfsPath'] = array(
      'SSHFS Path',
      'SSHFS_PATH',
      Log::readline("Please enter the path on which to mount your file system", "${_SERVER['HOME']}/aws-dev"),
    );
    $tokens['testSite'] = array(
      'Test Site',
      'DOMAIN',
      Log::readline("Please enter the URL of your test site", "$whoami.test.mysite.com"),
    );

    $tokens['autoHosts'] = array(
      'Auto Hosts',
      'AUTO_HOSTS',
      Log::readline("Would you like 'ektu start' to automatically generate your hosts file?", "", TRUE),
    );
    $tokens['autoHostsGentle'] = array(
      'Auto Hosts Gentle',
      'AUTO_GENTLE_HOSTS',
      'FALSE',
    );
    if ($tokens['autoHosts'] === 'TRUE') {
      $tokens['autoHostsGentle'][2] = Log::readline(
        "... and should I use the 'gentle' method? (Recommended if you don't want your hosts file overwritten).", "", TRUE
      );
    }
    $tokens['autoCFS'] = array(
      'Auto CFS',
      'AUTO_CFS',
      Log::readline("Would you like 'ektu start' to automatically connect the file system?", "", TRUE),
    );
    $tokens['autoDFS'] = array(
      'Auto DFS',
      'AUTO_DFS',
      Log::readline("Would you like 'ektu stop' to automatically disconnect the file system?", "", TRUE),
    );

    $tokens['date'] = array('Date', 'DATE', date(time()));

    log::logLine();
    Log::logBlank();
    Log::log("Thanks! Here are the settings you've entered:");
    Log::logLine();
    Log::logBlank();

    $tbl = new Console_Table(
      CONSOLE_TABLE_ALIGN_LEFT,
      '',
      1,
      'utf-8',
      FALSE
    );
    foreach ($tokens as $key => $token) {
      $tbl->addRow(array($token[0], (string) $token[2]));
    }
    Log::logPlain($tbl->getTable());

    Log::logBlank();
    $confirm = (Log::readline("Does this seem okay to you?", "", TRUE) === 'TRUE');

    if (!$confirm) {
      return Log::logError('Okay, aborting...');
    }

    Log::log('Saving files...');

    foreach (array('config.yaml', 'aws-settings.php') as $f) {
      copy("$this->dir/config/default.$f", "$this->dir/config/$f");
      $fileContents = file_get_contents("$this->dir/config/$f");
      foreach ($tokens as $key => $token) {
        $fileContents = str_replace($token[1], (string) $token[2], $fileContents);
      }
      file_put_contents("$this->dir/config/$f", $fileContents);
    }

  }
}
