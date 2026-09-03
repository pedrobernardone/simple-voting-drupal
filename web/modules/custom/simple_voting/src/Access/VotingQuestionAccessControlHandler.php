<?php

declare(strict_types=1);

namespace Drupal\simple_voting\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Access control for voting_question and voting_option entities.
 *
 * All administrative operations (create/update/delete) require the single
 * "administer simple voting" permission, keeping the permission model easy
 * to reason about for the case's "easy for the admin" non-functional
 * requirement. Anyone can view an active question (the CMS voting page has
 * its own access check for the actual voting action).
 */
class VotingQuestionAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account) {
    if ($account->hasPermission('administer simple voting')) {
      return AccessResult::allowed();
    }

    if ($operation === 'view') {
      return AccessResult::allowedIfHasPermission($account, 'cast simple voting vote')
        ->orIf(AccessResult::allowedIfHasPermission($account, 'view simple voting results'));
    }

    return AccessResult::neutral();
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL) {
    return AccessResult::allowedIfHasPermission($account, 'administer simple voting');
  }

}
