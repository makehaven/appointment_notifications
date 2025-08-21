<?php

/**
 * Implements hook_mail().
 */
function appointment_notifications_mail($key, &$message, $params) {
  switch ($key) {
    case 'appointment_member_scheduled':
    case 'appointment_host_scheduled':
    case 'appointment_canceled':
    case 'appointment_problem_notice':
    case 'appointment_feedback_invitation':
      $message['subject'] = $params['subject'];
      $message['body'][] = $params['message'];
      break;

    default:
      \Drupal::logger('appointment_notifications')->warning('Unhandled mail key: @key', ['@key' => $key]);
      break;
  }
}
