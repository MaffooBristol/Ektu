<?php

namespace Ektu\IP;

use Ektu\Util\Log\Log as Log;

class IP {

  /**
   * The parent class. I don't this is the best pattern but oh well.
   *
   * @var object
   */
  protected $app;

  /**
   * Assign the parent to the $app variable.
   *
   * @param object $app
   */
  public function __construct($app = NULL) {
    $this->app = $app;
  }

  /**
   * Gets the public IP of an instance.
   */
  protected function getPublicIP($iid = NULL) {

    $iid = $this->app->getIID($iid);
    $response = $this->app->getInstance($iid);

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
    Log::log("IP for {$this->app->getConnectTo()}: $ip");
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

}
