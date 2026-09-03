<?php

declare(strict_types=1);

namespace Drupal\simple_voting\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;

/**
 * Defines the Voting Vote entity.
 *
 * A vote records a single choice made by a voter for a given question. The
 * "voter_key" field is what actually enforces one-vote-per-user-per-question:
 * it is a normalized identifier computed as either "user:<uid>" for votes
 * cast inside the CMS by an authenticated Drupal user, or
 * "external:<external_user_id>" for votes cast through the external API. A
 * unique index on (question, voter_key) makes duplicate voting impossible at
 * the database level, not just in application code.
 *
 * @ContentEntityType(
 *   id = "voting_vote",
 *   label = @Translation("Vote"),
 *   label_collection = @Translation("Votes"),
 *   handlers = {
 *     "view_builder" = "Drupal\Core\Entity\EntityViewBuilder",
 *     "list_builder" = "Drupal\Core\Entity\EntityListBuilder",
 *     "access" = "Drupal\Core\Entity\EntityAccessControlHandler",
 *     "storage_schema" = "Drupal\simple_voting\Entity\VotingVoteStorageSchema",
 *   },
 *   base_table = "voting_vote",
 *   admin_permission = "administer simple voting",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *   },
 * )
 */
class VotingVote extends ContentEntityBase {

  /**
   * Builds the normalized voter key for an authenticated CMS user.
   */
  public static function buildVoterKeyForUser(int $uid): string {
    return 'user:' . $uid;
  }

  /**
   * Builds the normalized voter key for an external API caller.
   */
  public static function buildVoterKeyForExternalUser(string $externalUserId): string {
    return 'external:' . $externalUserId;
  }

  /**
   * Gets the id of the question this vote belongs to.
   */
  public function getQuestionId(): int {
    return (int) $this->get('question')->target_id;
  }

  /**
   * Gets the id of the option that was voted for.
   */
  public function getOptionId(): int {
    return (int) $this->get('option')->target_id;
  }

  /**
   * Gets the normalized voter key.
   */
  public function getVoterKey(): string {
    return (string) $this->get('voter_key')->value;
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['question'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Question'))
      ->setSetting('target_type', 'voting_question')
      ->setRequired(TRUE);

    $fields['option'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Option'))
      ->setSetting('target_type', 'voting_option')
      ->setRequired(TRUE);

    // Normalized unique identifier of the voter for this question. See the
    // class docblock for how it is built.
    $fields['voter_key'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Voter key'))
      ->setSetting('max_length', 255)
      ->setRequired(TRUE);

    // Populated only for votes cast by an authenticated Drupal user in the
    // CMS. NULL for votes coming from the external API.
    $fields['uid'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('User'))
      ->setSetting('target_type', 'user');

    // Populated only for votes cast through the external API. NULL for votes
    // cast by an authenticated Drupal user in the CMS.
    $fields['external_user_id'] = BaseFieldDefinition::create('string')
      ->setLabel(t('External user ID'))
      ->setSetting('max_length', 255);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Created'));

    return $fields;
  }

}
