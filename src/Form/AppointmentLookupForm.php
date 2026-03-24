<?php

namespace Drupal\appointment_booking\Form;

use Drupal\Core\Url;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

class AppointmentLookupForm extends FormBase {

  protected $entityTypeManager;

  public function __construct(EntityTypeManagerInterface $entityTypeManager) {
    $this->entityTypeManager = $entityTypeManager;
  }

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager')
    );
  }

  public function getFormId() {
    return 'appointment_lookup_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state) {

    $form['email'] = [
      '#type' => 'email',
      '#title' => $this->t('Enter your email'),
      '#required' => TRUE,
    ];

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Find my appointments'),
    ];

    // Display results after submit
    if ($appointments = $form_state->get('appointments')) {

      $form['results'] = [
        '#type' => 'markup',
        '#markup' => '<h3>My appointments</h3>',
      ];

      foreach ($appointments as $appointment) {

        $form['appointment_' . $appointment->id()] = [
            '#type' => 'container',
            '#attributes' => ['style' => 'margin-bottom:20px; padding:10px; border:1px solid #ccc;'],
        ];

        $form['appointment_' . $appointment->id()]['info'] = [
            '#markup' => '<strong>Date:</strong> ' . $appointment->get('field_appointment_date')->value . '<br>'
                . '<strong>Adviser ID:</strong> ' . $appointment->get('field_adviser')->target_id,
        ];

        $form['appointment_' . $appointment->id()]['edit'] = [
            '#type' => 'link',
            '#title' => $this->t('Edit'),
            '#url' => Url::fromRoute('appointment_booking.edit_public', [
                'appointment' => $appointment->id(),
            ]),
            '#attributes' => [
                'class' => ['button'],
                'style' => 'margin-right:10px;',
            ],
        ];

        $form['appointment_' . $appointment->id()]['cancel'] = [
            '#type' => 'submit',
            '#value' => $this->t('Cancel'),
            '#submit' => ['::cancelAppointment'],
            '#name' => 'cancel_' . $appointment->id(),
            '#appointment_id' => $appointment->id(),
        ];
      }
    }

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {

    $email = $form_state->getValue('email');

    $storage = $this->entityTypeManager->getStorage('appointment');

    $ids = $storage->getQuery()
      ->condition('field_email', $email)
      ->accessCheck(FALSE)
      ->execute();

    $appointments = $storage->loadMultiple($ids);

    $form_state->set('appointments', $appointments);
    $form_state->setRebuild(TRUE);
  }

  public function cancelAppointment(array &$form, FormStateInterface $form_state) {

    $trigger = $form_state->getTriggeringElement();
    $appointment_id = $trigger['#appointment_id'];

    $appointment = $this->entityTypeManager
      ->getStorage('appointment')
      ->load($appointment_id);

    if ($appointment) {
      $appointment->delete();
      $this->messenger()->addStatus('Appointment cancelled.');
    }

    $form_state->setRebuild(TRUE);
  }

}