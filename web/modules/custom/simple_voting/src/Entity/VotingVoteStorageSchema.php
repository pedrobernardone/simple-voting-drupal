<?php

declare(strict_types=1);

namespace Drupal\simple_voting\Entity;

use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\Sql\SqlContentEntityStorageSchema;

/**
 * Defines the storage schema for the Voting Vote entity.
 *
 * Adds a unique key on (question, voter_key) at the database level so that
 * duplicate votes are impossible even under concurrent requests, rather than
 * relying solely on an application-level check-then-insert.
 */
class VotingVoteStorageSchema extends SqlContentEntityStorageSchema {

  /**
   * {@inheritdoc}
   */
  protected function getEntitySchema(ContentEntityTypeInterface $entity_type, $reset = FALSE) {
    $schema = parent::getEntitySchema($entity_type, $reset);

    $base_table = $entity_type->getBaseTable() ?: $entity_type->id();
    if (isset($schema[$base_table])) {
      $schema[$base_table]['unique keys']['voting_vote__question_voter'] = [
        'question',
        'voter_key',
      ];
    }

    return $schema;
  }

}
