<?php

declare(strict_types=1);

namespace Drupal\simple_voting\Service;

/**
 * Thrown when a vote cannot be registered.
 *
 * The $reason property lets callers (controllers, forms) translate the
 * failure into the appropriate HTTP status code or user-facing message
 * without having to inspect the exception message string.
 */
class VotingException extends \RuntimeException {

  public const VOTING_DISABLED = 'voting_disabled';
  public const QUESTION_NOT_FOUND = 'question_not_found';
  public const QUESTION_INACTIVE = 'question_inactive';
  public const OPTION_NOT_FOUND = 'option_not_found';
  public const OPTION_MISMATCH = 'option_mismatch';
  public const ALREADY_VOTED = 'already_voted';
  public const INVALID_VOTER = 'invalid_voter';

  public function __construct(
    public readonly string $reason,
    string $message,
  ) {
    parent::__construct($message);
  }

}
