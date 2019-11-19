<?php

use Psr\Log\LoggerInterface;
use YellowCube\ART\Article;
use YellowCube\WAB\Order;

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

  public function log_create($level, $reference, $object, $message, array $context = []) {
    $service = 'SERVICE: ' . $_SERVER['REMOTE_ADDR'];
    // Log precise time.
    $t = microtime(true);
    $micro = sprintf("%06d", ($t - floor($t)) * 1000000);
    $d = new DateTime(date('Y-m-d H:i:s.' . $micro, $t));

    $datestr = $d->format("Y-m-d H:i:s.u");
    $lastrun = $this->yellowcube->lastRequest;

    $message = $datestr . ': ' . $lastrun . ' ' . $message;
    $message .= ' ' . var_export($context, TRUE);

    $this->yellowcube->log_create('', $service, $reference, $object, $message);
  }

  public function log($level, $message, array $context = []) {
    $reference = NULL;
    $object = NULL;
    $this->log_create($level, $reference, $object, $message, $context);
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
    $reference = NULL;
    $object = NULL;
    if (!empty($context['reference'])) {
      $reference = $context['reference'];
    }
    if (!empty($context['article']) && $context['article'] instanceof Article) {
      // @todo missing getters on WAB\Article.
    }
    if (!empty($context['order']) && $context['order'] instanceof Order) {
      $object = $context['order']->getOrderHeader()->getCustomerOrderNo();
    }
    $this->log_create('info', $reference, $object, $message, $context);
  }

  public function debug($message, array $context = array()) {
    // Skip logging debug messages such as each SOAP request.
  }

  public function warning($message, array $context = array()) {
    $this->log('warning', $message, $context);
  }
}
