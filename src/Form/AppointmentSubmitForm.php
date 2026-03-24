<?php

namespace Drupal\appointment_booking\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\appointment_booking\Service\AppointmentManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

class AppointmentSubmitForm extends FormBase {

  protected EntityTypeManagerInterface $entityTypeManager;
  protected AppointmentManager $appointmentManager;

  public function __construct(EntityTypeManagerInterface $entityTypeManager, AppointmentManager $appointmentManager) {
    $this->entityTypeManager = $entityTypeManager;
    $this->appointmentManager = $appointmentManager;
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('appointment_booking.appointment_manager')
    );
  }

  public function getFormId(): string {
    return 'appointment_submit_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['#tree'] = TRUE;

    $selected_type = $form_state->getValue('appointment_type');
    $selected_agency = $form_state->getValue('agency');
    $selected_adviser = $form_state->getValue(['dynamic', 'adviser']);
    $selected_date = $form_state->getValue(['dynamic', 'date']);

    // Appointment types.
    $terms = $this->entityTypeManager->getStorage('taxonomy_term')->loadTree('appointment_types');
    $type_options = [];
    foreach ($terms as $term) {
      $type_options[$term->tid] = $term->name;
    }

    $form['appointment_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Appointment type'),
      '#options' => $type_options,
      '#empty_option' => $this->t('- Select -'),
      '#required' => TRUE,
      '#ajax' => [
        'callback' => '::updateByTypeOrAgency',
        'wrapper' => 'booking-dynamic-wrapper',
      ],
    ];

    // Agencies.
    $agencies = $this->entityTypeManager->getStorage('agency')->loadMultiple();
    $agency_options = [];
    foreach ($agencies as $agency) {
      $agency_options[$agency->id()] = $agency->label();
    }

    $form['agency'] = [
      '#type' => 'select',
      '#title' => $this->t('Agency'),
      '#options' => $agency_options,
      '#empty_option' => $this->t('- Select -'),
      '#required' => TRUE,
      '#ajax' => [
        'callback' => '::updateByTypeOrAgency',
        'wrapper' => 'booking-dynamic-wrapper',
      ],
    ];

    $form['dynamic'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'booking-dynamic-wrapper'],
    ];

    // Advisers filtered by agency + type.
    $adviser_options = [];
    if (!empty($selected_type) && !empty($selected_agency)) {
      $advisers = $this->appointmentManager->getAdvisers((int) $selected_agency, (int) $selected_type);
      foreach ($advisers as $adviser) {
        $adviser_options[$adviser->id()] = $adviser->getDisplayName();
      }
    }

    if (!isset($adviser_options[$selected_adviser])) {
        $selected_adviser = NULL;
    }

    $form['dynamic']['adviser'] = [
      '#type' => 'select',
      '#title' => $this->t('Adviser'),
      '#options' => $adviser_options,
      '#empty_option' => $this->t('- Select -'),
      '#required' => TRUE,
      '#disabled' => empty($selected_type) || empty($selected_agency),
      '#ajax' => [
        'callback' => '::updateByAdviserOrDate',
        'wrapper' => 'booking-dynamic-wrapper',
      ],
    ];

    $form['dynamic']['date'] = [
      '#type' => 'date',
      '#title' => $this->t('Choose date'),
      '#required' => TRUE,
      '#ajax' => [
        'callback' => '::updateByAdviserOrDate',
        'wrapper' => 'booking-dynamic-wrapper',
      ],
    ];

    // Slots filtered by adviser + date.
    $slot_options = [];
    if (!empty($selected_adviser) && !empty($selected_date)) {
      $slots = $this->appointmentManager->getAvailableSlots((int) $selected_adviser, $selected_date);
      foreach ($slots as $slot) {
        $slot_options[$slot['value']] = $slot['label'];
      }
    }

    $selected_slot = $form_state->getValue(['dynamic', 'slot']);

    if (!isset($slot_options[$selected_slot])) {
        $selected_slot = NULL;
    }

    $form['dynamic']['slot'] = [
      '#type' => 'select',
      '#title' => $this->t('Available time'),
      '#options' => $slot_options,
      '#empty_option' => $this->t('- Select -'),
      '#required' => TRUE,
      '#disabled' => empty($selected_adviser) || empty($selected_date),
    ];

    $form['dynamic']['first_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('First name'),
      '#required' => TRUE,
    ];

    $form['dynamic']['last_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Last name'),
      '#required' => TRUE,
    ];

    $form['dynamic']['phone'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Phone'),
      '#required' => TRUE,
    ];

    $form['dynamic']['email'] = [
      '#type' => 'email',
      '#title' => $this->t('Email'),
      '#required' => TRUE,
    ];

    $form['dynamic']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Book appointment'),
    ];

    return $form;
  }

  public function updateDynamicFields(array &$form, FormStateInterface $form_state): array {
    return $form['dynamic'];
  }

  public function updateByTypeOrAgency(array &$form, FormStateInterface $form_state): array {
    $form_state->setValue(['dynamic', 'adviser'], NULL);
    $form_state->setValue(['dynamic', 'slot'], NULL);
    return $form['dynamic'];
  }

  public function updateByAdviserOrDate(array &$form, FormStateInterface $form_state): array {
    $form_state->setValue(['dynamic', 'slot'], NULL);
    return $form['dynamic'];
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $slot = $form_state->getValue(['dynamic', 'slot']);
    $adviser = $form_state->getValue(['dynamic', 'adviser']);

    if (!empty($slot) && !empty($adviser)) {
      if (!$this->appointmentManager->isSlotAvailable((int) $adviser, $slot)) {
        $form_state->setErrorByName('dynamic][slot', $this->t('This slot is no longer available.'));
      }
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $appointment_type = $form_state->getValue('appointment_type');
    $agency = $form_state->getValue('agency');
    $adviser = $form_state->getValue(['dynamic', 'adviser']);
    $slot = $form_state->getValue(['dynamic', 'slot']);
    $first_name = $form_state->getValue(['dynamic', 'first_name']);
    $last_name = $form_state->getValue(['dynamic', 'last_name']);
    $phone = $form_state->getValue(['dynamic', 'phone']);
    $email = $form_state->getValue(['dynamic', 'email']);

    // Final safety check.
    if (!$this->appointmentManager->isSlotAvailable((int) $adviser, $slot)) {
      $this->messenger()->addError($this->t('This slot is no longer available.'));
      return;
    }

    $label = $first_name . ' ' . $last_name . ' - ' . $slot;

    $appointment = $this->entityTypeManager
      ->getStorage('appointment')
      ->create([
        'label' => $label,
        'field_appointment_type' => $appointment_type,
        'field_agency' => $agency,
        'field_adviser' => $adviser,
        'field_appointment_date' => $slot,
        'field_first_name' => $first_name,
        'field_last_name' => $last_name,
        'field_phone' => $phone,
        'field_email' => $email,
        'field_status' => 'scheduled',
      ]);

    $appointment->save();

    $this->messenger()->addStatus($this->t('Appointment successfully booked.'));
    $form_state->setRedirectUrl(Url::fromRoute('<current>'));
  }

}