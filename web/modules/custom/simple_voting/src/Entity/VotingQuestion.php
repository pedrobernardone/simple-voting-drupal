<?php

declare(strict_types=1);

namespace Drupal\simple_voting\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Entity\EntityPublishedTrait;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\user\EntityOwnerInterface;
use Drupal\user\EntityOwnerTrait;

/**
 * Defines the Voting Question entity.
 *
 * A question is the top-level unit of the voting system. It has one or more
 * VotingOption entities attached to it and a per-question setting that
 * controls whether aggregated results are visible to end users after they
 * vote.
 *
 * @ContentEntityType(
 *   id = "voting_question",
 *   label = @Translation("Voting question"),
 *   label_collection = @Translation("Voting questions"),
 *   handlers = {
 *     "view_builder" = "Drupal\Core\Entity\EntityViewBuilder",
 *     "list_builder" = "Drupal\simple_voting\ListBuilder\VotingQuestionListBuilder",
 *     "access" = "Drupal\simple_voting\Access\VotingQuestionAccessControlHandler",
 *     "form" = {
 *       "add" = "Drupal\simple_voting\Form\VotingQuestionForm",
 *       "edit" = "Drupal\simple_voting\Form\VotingQuestionForm",
 *       "delete" = "Drupal\Core\Entity\ContentEntityDeleteForm",
 *     },
 *   },
 *   base_table = "voting_question",
 *   admin_permission = "administer simple voting",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "label" = "title",
 *     "published" = "status",
 *     "owner" = "uid",
 *   },
 *   links = {
 *     "canonical" = "/admin/content/voting-question/{voting_question}",
 *     "add-form" = "/admin/content/voting-question/add",
 *     "edit-form" = "/admin/content/voting-question/{voting_question}/edit",
 *     "delete-form" = "/admin/content/voting-question/{voting_question}/delete",
 *     "collection" = "/admin/content/voting-question",
 *   },
 *   field_ui_base_route = "entity.voting_question.collection",
 * )
 */
class VotingQuestion extends ContentEntityBase implements EntityOwnerInterface, EntityPublishedInterface {

  use EntityChangedTrait;
  use EntityPublishedTrait;
  use EntityOwnerTrait;

  /**
   * Gets the question title.
   */
  public function getTitle(): string {
    return (string) $this->get('title')->value;
  }

  /**
   * Gets the question description (plain value, as entered by the admin).
   */
  public function getDescription(): string {
    return (string) $this->get('description')->value;
  }

  /**
   * Whether aggregated results should be shown to the voter after voting.
   */
  public function shouldShowResults(): bool {
    return (bool) $this->get('show_results')->value;
  }

  /**
   * Whether this question is currently open for voting.
   */
  public function isActive(): bool {
    return (bool) $this->get('status')->value;
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields += static::ownerBaseFieldDefinitions($entity_type);
    $fields += static::publishedBaseFieldDefinitions($entity_type);

    $fields['title'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Title'))
      ->setDescription(t('The question title shown to voters.'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 255)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => -10,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['description'] = BaseFieldDefinition::create('text_long')
      ->setLabel(t('Description'))
      ->setDescription(t('An optional, longer explanation of the question.'))
      ->setDisplayOptions('form', [
        'type' => 'text_textarea',
        'weight' => -5,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['show_results'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('Show results after voting'))
      ->setDescription(t('If enabled, the aggregated vote totals are shown to the user right after they vote on this question.'))
      ->setDefaultValue(TRUE)
      ->setDisplayOptions('form', [
        'type' => 'boolean_checkbox',
        'weight' => 0,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['status']
      ->setLabel(t('Active'))
      ->setDescription(t('Whether this question currently accepts votes.'))
      ->setDisplayOptions('form', [
        'type' => 'boolean_checkbox',
        'weight' => 1,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['uid']
      ->setLabel(t('Author'))
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayConfigurable('form', TRUE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Created'))
      ->setDisplayConfigurable('view', TRUE);

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(t('Changed'));

    return $fields;
  }

}
