<?php

declare(strict_types=1);

namespace Drupal\simple_voting\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;

/**
 * Defines the Voting Option entity.
 *
 * An option represents a single selectable answer for a VotingQuestion. It
 * may optionally carry an image and a short description in addition to its
 * title.
 *
 * @ContentEntityType(
 *   id = "voting_option",
 *   label = @Translation("Voting option"),
 *   label_collection = @Translation("Voting options"),
 *   handlers = {
 *     "view_builder" = "Drupal\Core\Entity\EntityViewBuilder",
 *     "list_builder" = "Drupal\Core\Entity\EntityListBuilder",
 *     "access" = "Drupal\simple_voting\Access\VotingQuestionAccessControlHandler",
 *     "form" = {
 *       "add" = "Drupal\simple_voting\Form\VotingOptionForm",
 *       "edit" = "Drupal\simple_voting\Form\VotingOptionForm",
 *       "delete" = "Drupal\Core\Entity\ContentEntityDeleteForm",
 *     },
 *     "route_provider" = {
 *       "html" = "Drupal\Core\Entity\Routing\AdminHtmlRouteProvider",
 *     },
 *   },
 *   base_table = "voting_option",
 *   admin_permission = "administer simple voting",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "label" = "title",
 *   },
 *   links = {
 *     "canonical" = "/admin/content/voting-option/{voting_option}",
 *     "add-form" = "/admin/content/voting-question/{voting_question}/option/add",
 *     "edit-form" = "/admin/content/voting-option/{voting_option}/edit",
 *     "delete-form" = "/admin/content/voting-option/{voting_option}/delete",
 *   },
 * )
 */
class VotingOption extends ContentEntityBase {

  use EntityChangedTrait;

  /**
   * Gets the option title.
   */
  public function getTitle(): string {
    return (string) $this->get('title')->value;
  }

  /**
   * Gets the option description.
   */
  public function getDescription(): string {
    return (string) $this->get('description')->value;
  }

  /**
   * Gets the id of the parent question.
   */
  public function getQuestionId(): ?int {
    $value = $this->get('question')->target_id;
    return $value === NULL ? NULL : (int) $value;
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['question'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Question'))
      ->setDescription(t('The voting question this option belongs to.'))
      ->setSetting('target_type', 'voting_question')
      ->setRequired(TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['title'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Title'))
      ->setDescription(t('The short label of this answer option.'))
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
      ->setDescription(t('An optional, short description of this option.'))
      ->setDisplayOptions('form', [
        'type' => 'text_textarea',
        'weight' => -5,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['image'] = BaseFieldDefinition::create('image')
      ->setLabel(t('Image'))
      ->setDescription(t('An optional image representing this option.'))
      ->setSetting('file_directory', 'voting-options')
      ->setSetting('alt_field_required', FALSE)
      ->setDisplayOptions('form', [
        'type' => 'image_image',
        'weight' => 0,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['weight'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Weight'))
      ->setDescription(t('The display order of this option within its question.'))
      ->setDefaultValue(0)
      ->setDisplayConfigurable('form', TRUE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Created'));

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(t('Changed'));

    return $fields;
  }

}
