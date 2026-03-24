<?php

namespace Drupal\appointment_booking\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\Response;

class AppointmentExportController extends ControllerBase {

  public function exportCsv(): Response {
    $storage = $this->entityTypeManager()->getStorage('appointment');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->sort('field_appointment_date.value', 'ASC')
      ->execute();

    $appointments = $storage->loadMultiple($ids);

    $handle = fopen('php://temp', 'r+');

    // CSV headers.
    fputcsv($handle, [
      'ID',
      'Label',
      'Date',
      'Agency',
      'Adviser',
      'Appointment Type',
      'First Name',
      'Last Name',
      'Email',
      'Phone',
      'Status',
    ]);

    foreach ($appointments as $appointment) {
      $agency = $appointment->get('field_agency')->entity;
      $adviser = $appointment->get('field_adviser')->entity;
      $type = $appointment->get('field_appointment_type')->entity;

      fputcsv($handle, [
        $appointment->id(),
        $appointment->label(),
        $appointment->get('field_appointment_date')->value,
        $agency ? $agency->label() : '',
        $adviser ? $adviser->label() : '',
        $type ? $type->label() : '',
        $appointment->get('field_first_name')->value,
        $appointment->get('field_last_name')->value,
        $appointment->get('field_email')->value,
        $appointment->get('field_phone')->value,
        $appointment->get('field_status')->value,
      ]);
    }

    rewind($handle);
    $csv = stream_get_contents($handle);
    fclose($handle);

    $response = new Response($csv);
    $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
    $response->headers->set('Content-Disposition', 'attachment; filename="appointments.csv"');

    return $response;
  }

}