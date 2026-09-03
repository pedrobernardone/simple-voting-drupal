<?php

declare(strict_types=1);

namespace Drupal\simple_voting\ListBuilder;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\simple_voting\Entity\VotingQuestion;

/**
 * List builder for the admin "Voting questions" overview page.
 *
 * Shows, at a glance, the two settings the case explicitly calls out per
 * question (results visibility and active/inactive) so the admin does not
 * need to open each question just to check its configuration.
 */
class VotingQuestionListBuilder extends EntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader() {
    $header['title'] = $this->t('Title');
    $header['status'] = $this->t('Active');
    $header['show_results'] = $this->t('Shows results');
    $header['options_count'] = $this->t('Options');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity) {
    /** @var \Drupal\simple_voting\Entity\VotingQuestion $entity */
    $row['title'] = $entity->toLink($entity->getTitle());
    $row['status'] = $entity->isActive() ? $this->t('Yes') : $this->t('No');
    $row['show_results'] = $entity->shouldShowResults() ? $this->t('Yes') : $this->t('No');
    $row['options_count'] = $this->countOptions($entity);
    return $row + parent::buildRow($entity);
  }

  /**
   * Counts the answer options attached to a question.
   */
  protected function countOptions(VotingQuestion $question): int {
    $storage = \Drupal::entityTypeManager()->getStorage('voting_option');
    return (int) $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('question', $question->id())
      ->count()
      ->execute();
  }

}
