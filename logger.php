<?php

use Psr\Log\LoggerInterface;

/**
 * Class Logger
 *
 * Logs SOAP errors to YClog.
 */
class Logger implements LoggerInterface {
  /**
   * @var \WooYellowCube
   */
  protected $yellowcube;

  public function __construct($yellowcube) {
    $this->yellowcube = $yellowcube;
  }

  public function log($level, $message, array $context = []) {
    $service = 'SERVICE: ' . $_SERVER['REMOTE_ADDR'];
    // @todo log precise time.
    $t = microtime(true);
    $micro = sprintf("%06d", ($t - floor($t)) * 1000000);
    $d = new DateTime(date('Y-m-d H:i:s.' . $micro, $t));

    $datestr = $d->format("Y-m-d H:i:s.u");
    $lastrun = $this->yellowcube->lastRequest;

    $this->yellowcube->log_create('', $service, NULL, NULL, $datestr . ': ' . $lastrun . ' ' . $message);
  }

  public function notice($message, array $context = array()) {
    $this->log('notice', $message, $context);
  }

  public function emergency($message, array $context = array()) {
    $this->log('emergency', $message, $context);
  }

  public function alert($message, array $context = array()) {
    $this->log('alert', $message, $context);
  }

  public function critical($message, array $context = array()) {
    $this->log('critical', $message, $context);
  }

  public function error($message, array $context = array()) {
    $this->log('error', $message, $context);
  }

  public function info($message, array $context = array()) {
    $this->log('info', $message, $context);
  }

  public function debug($message, array $context = array()) {
    // Skip logging debug messages such as each SOAP request.
  }

  public function warning($message, array $context = array()) {
    $this->log('warning', $message, $context);
  }
}
