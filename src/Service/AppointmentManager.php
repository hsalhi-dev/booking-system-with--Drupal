<?php

namespace Drupal\appointment_booking\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\user\UserInterface;

class AppointmentManager {

  protected EntityTypeManagerInterface $entityTypeManager;

  public function __construct(EntityTypeManagerInterface $entityTypeManager) {
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * Returns advisers filtered by agency and/or appointment type.
   */
  public function getAdvisers(?int $agency_id = NULL, ?int $appointment_type_id = NULL): array {
    $storage = $this->entityTypeManager->getStorage('user');
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', 1)
      ->condition('roles', 'adviser');

    if ($agency_id) {
      $query->condition('field_agency.target_id', $agency_id);
    }

    if ($appointment_type_id) {
      $query->condition('field_specializations.target_id', $appointment_type_id);
    }

    $uids = $query->execute();
    return $storage->loadMultiple($uids);
  }

  /**
   * Returns appointments for one adviser.
   */
  public function getAppointmentsByAdviser(int $adviser_id): array {
    $storage = $this->entityTypeManager->getStorage('appointment');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_adviser.target_id', $adviser_id)
      ->condition('field_status.value', 'scheduled')
      ->sort('field_appointment_date.value', 'ASC')
      ->execute();

    return $storage->loadMultiple($ids);
  }

  /**
   * Checks if a datetime slot is available for an adviser.
   */
  public function isSlotAvailable(int $adviser_id, string $datetime, ?int $exclude_appointment_id = NULL): bool {
    $storage = $this->entityTypeManager->getStorage('appointment');
    $query = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('field_adviser.target_id', $adviser_id)
        ->condition('field_appointment_date.value', $datetime)
        ->condition('field_status.value', 'scheduled');

    if ($exclude_appointment_id) {
        $query->condition('id', $exclude_appointment_id, '<>');
    }

    $ids = $query->execute();

    return empty($ids);
  }

  /**
   * Generates 30-minute available slots for one adviser and one date.
   */
  public function getAvailableSlots(int $adviser_id, string $date, ?int $exclude_appointment_id = NULL): array {
    $slots = [];

    $start = new \DateTime($date . ' 09:00:00');
    $end = new \DateTime($date . ' 17:00:00');
    $interval = new \DateInterval('PT30M');

    for ($time = clone $start; $time < $end; $time->add($interval)) {
        $datetime = $time->format('Y-m-d\TH:i:s');
        if ($this->isSlotAvailable($adviser_id, $datetime, $exclude_appointment_id)) {
        $slots[] = [
            'value' => $datetime,
            'label' => $time->format('H:i'),
        ];
        }
    }

    return $slots;
  }

}