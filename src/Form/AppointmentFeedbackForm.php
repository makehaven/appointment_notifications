<?php

namespace Drupal\appointment_notifications\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides an appointment feedback form.
 */
class AppointmentFeedbackForm extends FormBase {

  /**
   * The current route match.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected $routeMatch;

  /**
   * Constructs a new AppointmentFeedbackForm object.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The current route match.
   */
  public function __construct(RouteMatchInterface $route_match) {
    $this->routeMatch = $route_match;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('current_route_match')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'appointment_feedback_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $node = NULL) {
    if (!$node || $node->bundle() !== 'appointment') {
      $this->messenger()->addError($this->t('The provided node is not an appointment.'));
      return [];
    }

    $current_uid = (int) $this->currentUser()->id();
    $owner_uid = (int) $node->getOwnerId();
    $host_uid = 0;
    if ($node->hasField('field_appointment_host') && !$node->get('field_appointment_host')->isEmpty()) {
      $host_uid = (int) $node->get('field_appointment_host')->target_id;
    }

    $can_access = $this->currentUser()->hasPermission('administer nodes')
      || ($current_uid > 0 && $current_uid === $owner_uid)
      || ($current_uid > 0 && $current_uid === $host_uid);

    if (!$can_access) {
      $this->messenger()->addError($this->t('You do not have access to submit feedback for this appointment.'));
      return [];
    }

    $form_state->set('node', $node);

    $form['feedback'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Please provide your feedback for the appointment.'),
      '#required' => TRUE,
      '#description' => $this->t('Your feedback helps us improve our program.'),
    ];

    $form['actions']['#type'] = 'actions';
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Submit Feedback'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    /** @var \Drupal\node\NodeInterface $node */
    $node = $form_state->get('node');
    $feedback = $form_state->getValue('feedback');

    if ($node->hasField('field_appointment_feedback')) {
      $node->set('field_appointment_feedback', $feedback);
      $node->save();
      $this->messenger()->addStatus($this->t('Thank you for your feedback!'));
    }
    else {
      $this->messenger()->addError($this->t('The appointment node does not have the feedback field.'));
    }

    $form_state->setRedirect('<front>');
  }

}
