<?php

namespace Drupal\ymca_google;

use Drupal\Core\State\StateInterface;

/**
 * Class GcalGroupexWrapper.
 *
 * @package Drupal\ymca_google
 */
class GcalGroupexWrapper implements GcalGroupexWrapperInterface {

  /**
   * The name of key to store schedule.
   */
  const SCHEDULE_KEY = 'ymca_google_syncer_schedule';

  /**
   * Number steps.
   *
   * @var int
   */
  private $steps = 180;

  /**
   * Step length.
   *
   * @var int
   */
  private $length = 43200;

  /**
   * Raw source data from source system.
   *
   * @var array
   */
  protected $sourceData = [];

  /**
   * Prepared data for proxy system.
   *
   * @var array
   */
  protected $proxyData = [];

  /**
   * Time frame for the data.
   *
   * @var array
   */
  protected $timeFrame = [];

  /**
   * State.
   *
   * @var StateInterface
   */
  protected $state;

  /**
   * GcalGroupexWrapper constructor.
   *
   * @param StateInterface $state
   *   State.
   */
  public function __construct(StateInterface $state) {
    $this->state = $state;
  }

  /**
   * Source data setter.
   *
   * @param array $data
   *   Source data from Groupex.
   */
  public function setSourceData(array $data) {
    $this->sourceData = $data;
  }

  /**
   * Source data getter.
   */
  public function getSourceData() {
    return $this->sourceData;
  }

  /**
   * {@inheritdoc}
   */
  public function getProxyData() {
    return $this->proxyData;
  }

  /**
   * {@inheritdoc}
   */
  public function setProxyData(array $data) {
    $this->proxyData = $data;
  }

  /**
   * {@inheritdoc}
   */
  public function setTimeFrame(array $frame) {
    $this->timeFrame = $frame;
  }

  /**
   * {@inheritdoc}
   */
  public function getTimeFrame() {
    return $this->timeFrame;
  }

  /**
   * {@inheritdoc}
   */
  public function next($noExceptions = TRUE) {
    $schedule = $this->getSchedule();
    $next = $schedule['current'] + 1;
    if ($next >= $this->steps) {
      // We reached the end. Build new one.
      $new_schedule = $this->buildSchedule(REQUEST_TIME);
    }
    else {
      // Update current step pointer.
      $new_schedule = $schedule;
      $new_schedule['current'] = $next;
    }

    // Save schedule.
    $this->state->set(self::SCHEDULE_KEY, $new_schedule);
  }

  /**
   * {@inheritdoc}
   */
  public function getSchedule() {
    if (!$schedule = $this->state->get(self::SCHEDULE_KEY)) {
      $schedule = $this->buildSchedule(REQUEST_TIME);
      $this->state->set(self::SCHEDULE_KEY, $schedule);
    }

    return $schedule;
  }

  /**
   * Build schedule.
   *
   * @param int $start
   *   Start timestamp.
   *
   * @return array
   *   Schedule.
   */
  private function buildSchedule($start) {
    $schedule = [
      'steps' => [],
      'current' => 0,
    ];
    for ($i = 0; $i < $this->steps; $i++) {
      if ($i == 0) {
        $schedule['steps'][$i]['start'] = $start;
      }
      else {
        $schedule['steps'][$i]['start'] = $schedule['steps'][$i - 1]['end'];
      }
      $schedule['steps'][$i]['end'] = $schedule['steps'][$i]['start'] + $this->length;
    }
    return $schedule;
  }

  /**
   * Remove schedule.
   *
   * Used for resetting current schedule.
   */
  public function removeSchedule() {
    $this->state->delete(self::SCHEDULE_KEY);
  }

}
