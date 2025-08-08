<?php

/**
 * Implements hook_mail().
 */
function appointment_notifications_mail($key, &$message, $params) {
  switch ($key) {
    case 'appointment_created':
    case 'appointment_canceled':
      $message['subject'] = $params['subject'];
      $message['body'][] = $params['message'];
      break;

    default:
      \Drupal::logger('appointment_notifications')->warning('Unhandled mail key: @key', ['@key' => $key]);
      break;
  }
}
