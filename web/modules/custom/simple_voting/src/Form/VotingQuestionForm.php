<?php

declare(strict_types=1);

namespace Drupal\simple_voting\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\simple_voting\Entity\VotingQuestion;

/**
 * Admin form for creating and editing voting questions.
 *
 * Deliberately kept as a thin ContentEntityForm: the base field widgets
 * (title, description, show_results, status) already give the admin an
 * intuitive, standard Drupal editing experience without any custom markup,
 * satisfying the "easy and intuitive for the admin" requirement without
 * extra code to maintain.
 */
class VotingQuestionForm extends ContentEntityForm {

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    /** @var \Drupal\simple_voting\Entity\VotingQuestion $entity */
    $entity = $this->entity;
    $isNew = $entity->isNew();
    $result = parent::save($form, $form_state);

    $message = $isNew
      ? $this->t('Question %title has been created.', ['%title' => $entity->getTitle()])
      : $this->t('Question %title has been updated.', ['%title' => $entity->getTitle()]);
    $this->messenger()->addStatus($message);

    $form_state->setRedirect('entity.voting_question.collection');

    return $result;
  }

  /**
   * {@inheritdoc}
   */
  protected function actions(array $form, FormStateInterface $form_state) {
    $actions = parent::actions($form, $form_state);
    $actions['submit']['#value'] = $this->t('Save question');
    return $actions;
  }

}
