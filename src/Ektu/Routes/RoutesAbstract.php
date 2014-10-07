<?php

namespace Ektu\Routes;

use \Ektu\Routes\IP as IP;

abstract class RoutesAbstract {
  static function test() {
    return IP\something::IP();
  }
}
