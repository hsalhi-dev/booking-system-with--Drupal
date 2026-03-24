<?php

namespace Drupal\appointment_booking\Form;

use Drupal\appointment_booking\Service\AppointmentManager;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

class AppointmentEditPublicForm extends FormBase {

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
    return 'appointment_edit_public_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state, $appointment = NULL): array {
    $form['#tree'] = TRUE;

    $appointment_entity = $this->entityTypeManager->getStorage('appointment')->load($appointment);

    if (!$appointment_entity) {
      $this->messenger()->addError($this->t('Appointment not found.'));
      return $form;
    }

    $form['appointment_id'] = [
      '#type' => 'hidden',
      '#value' => $appointment_entity->id(),
    ];

    $selected_type = $form_state->getValue('appointment_type') ?? $appointment_entity->get('field_appointment_type')->target_id;
    $selected_agency = $form_state->getValue('agency') ?? $appointment_entity->get('field_agency')->target_id;
    $selected_adviser = $form_state->getValue(['dynamic', 'adviser']) ?? $appointment_entity->get('field_adviser')->target_id;

    $stored_datetime = $appointment_entity->get('field_appointment_date')->value;
    $default_date = substr($stored_datetime, 0, 10);
    $selected_date = $form_state->getValue(['dynamic', 'date']) ?? $default_date;

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
      '#default_value' => $selected_type,
      '#required' => TRUE,
      '#ajax' => [
        'callback' => '::updateByTypeOrAgency',
        'wrapper' => 'edit-dynamic-wrapper',
      ],
    ];

    // Agencies.
    $agencies = $this->entityTypeManager->getStorage('agency')->loadMultiple();
    $agency_options = [];
    foreach ($agencies as $agency_entity) {
      $agency_options[$agency_entity->id()] = $agency_entity->label();
    }

    $form['agency'] = [
      '#type' => 'select',
      '#title' => $this->t('Agency'),
      '#options' => $agency_options,
      '#default_value' => $selected_agency,
      '#required' => TRUE,
      '#ajax' => [
        'callback' => '::updateByTypeOrAgency',
        'wrapper' => 'edit-dynamic-wrapper',
      ],
    ];

    $form['dynamic'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'edit-dynamic-wrapper'],
    ];

    // Advisers.
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
      '#default_value' => $selected_adviser,
      '#required' => TRUE,
      '#disabled' => empty($selected_type) || empty($selected_agency),
      '#ajax' => [
        'callback' => '::updateByAdviserOrDate',
        'wrapper' => 'edit-dynamic-wrapper',
      ],
    ];

    $form['dynamic']['date'] = [
      '#type' => 'date',
      '#title' => $this->t('Choose date'),
      '#default_value' => $selected_date,
      '#required' => TRUE,
      '#ajax' => [
        'callback' => '::updateByAdviserOrDate',
        'wrapper' => 'edit-dynamic-wrapper',
      ],
    ];

    // Slots.
    $slot_options = [];
    if (!empty($selected_adviser) && !empty($selected_date)) {
      $slots = $this->appointmentManager->getAvailableSlots(
        (int) $selected_adviser,
        $selected_date,
        (int) $appointment_entity->id()
      );

      foreach ($slots as $slot) {
        $slot_options[$slot['value']] = $slot['label'];
      }

      // Ensure current slot is still present if it matches current adviser/date.
      if (substr($stored_datetime, 0, 10) === $selected_date && (int) $appointment_entity->get('field_adviser')->target_id === (int) $selected_adviser) {
        $slot_options[$stored_datetime] = substr($stored_datetime, 11, 5);
        ksort($slot_options);
      }
    }

    $selected_slot = $form_state->getValue(['dynamic', 'slot']) ?? $stored_datetime;

    if (!isset($slot_options[$selected_slot])) {
        $selected_slot = NULL;
    }

    $form['dynamic']['slot'] = [
      '#type' => 'select',
      '#title' => $this->t('Available time'),
      '#options' => $slot_options,
      '#default_value' => $selected_slot,
      '#required' => TRUE,
      '#disabled' => empty($selected_adviser) || empty($selected_date),
    ];

    $form['dynamic']['first_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('First name'),
      '#default_value' => $form_state->getValue(['dynamic', 'first_name']) ?? $appointment_entity->get('field_first_name')->value,
      '#required' => TRUE,
    ];

    $form['dynamic']['last_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Last name'),
      '#default_value' => $form_state->getValue(['dynamic', 'last_name']) ?? $appointment_entity->get('field_last_name')->value,
      '#required' => TRUE,
    ];

    $form['dynamic']['phone'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Phone'),
      '#default_value' => $form_state->getValue(['dynamic', 'phone']) ?? $appointment_entity->get('field_phone')->value,
      '#required' => TRUE,
    ];

    $form['dynamic']['email'] = [
      '#type' => 'email',
      '#title' => $this->t('Email'),
      '#default_value' => $form_state->getValue(['dynamic', 'email']) ?? $appointment_entity->get('field_email')->value,
      '#required' => TRUE,
    ];

    $form['dynamic']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save changes'),
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
    $appointment_id = (int) $form_state->getValue('appointment_id');
    $slot = $form_state->getValue(['dynamic', 'slot']);
    $adviser = $form_state->getValue(['dynamic', 'adviser']);

    if (!empty($slot) && !empty($adviser)) {
      if (!$this->appointmentManager->isSlotAvailable((int) $adviser, $slot, $appointment_id)) {
        $form_state->setErrorByName('dynamic][slot', $this->t('This slot is no longer available.'));
      }
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $appointment_id = (int) $form_state->getValue('appointment_id');

    $appointment = $this->entityTypeManager
      ->getStorage('appointment')
      ->load($appointment_id);

    if (!$appointment) {
      $this->messenger()->addError($this->t('Appointment not found.'));
      return;
    }

    $appointment_type = $form_state->getValue('appointment_type');
    $agency = $form_state->getValue('agency');
    $adviser = $form_state->getValue(['dynamic', 'adviser']);
    $slot = $form_state->getValue(['dynamic', 'slot']);
    $first_name = $form_state->getValue(['dynamic', 'first_name']);
    $last_name = $form_state->getValue(['dynamic', 'last_name']);
    $phone = $form_state->getValue(['dynamic', 'phone']);
    $email = $form_state->getValue(['dynamic', 'email']);

    if (!$this->appointmentManager->isSlotAvailable((int) $adviser, $slot, $appointment_id)) {
      $this->messenger()->addError($this->t('This slot is no longer available.'));
      return;
    }

    $label = $first_name . ' ' . $last_name . ' - ' . $slot;

    $appointment->set('label', $label);
    $appointment->set('field_appointment_type', $appointment_type);
    $appointment->set('field_agency', $agency);
    $appointment->set('field_adviser', $adviser);
    $appointment->set('field_appointment_date', $slot);
    $appointment->set('field_first_name', $first_name);
    $appointment->set('field_last_name', $last_name);
    $appointment->set('field_phone', $phone);
    $appointment->set('field_email', $email);
    $appointment->save();

    $this->messenger()->addStatus($this->t('Appointment updated successfully.'));
    $form_state->setRedirectUrl(Url::fromRoute('appointment_booking.lookup'));
  }

}