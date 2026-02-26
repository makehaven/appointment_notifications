<?php

namespace Drupal\appointment_notifications\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\node\NodeInterface;

/**
 * Access checker for signed appointment cancellation links.
 */
final class CancelAppointmentLinkAccess {

  /**
   * Validates signed cancellation URL parameters.
   */
  public static function access(NodeInterface $node, string $audience, int $expires, string $token): AccessResult {
    if ($node->bundle() !== 'appointment') {
      return AccessResult::forbidden()->setCacheMaxAge(0);
    }

    if (!in_array($audience, ['member', 'host'], TRUE)) {
      return AccessResult::forbidden()->setCacheMaxAge(0);
    }

    $recipient = _appointment_notifications_cancel_recipient($node, $audience);
    if (empty($recipient['email'])) {
      return AccessResult::forbidden()->setCacheMaxAge(0);
    }

    $is_valid = _appointment_notifications_cancel_token_is_valid(
      $node,
      $audience,
      $expires,
      $token,
      $recipient['email']
    );

    return AccessResult::allowedIf($is_valid)->setCacheMaxAge(0);
  }

}
