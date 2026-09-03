<?php

declare(strict_types=1);

namespace Drupal\simple_voting\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\simple_voting\Entity\VotingQuestion;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Admin form for creating and editing answer options for a question.
 *
 * When adding a new option, the parent question is taken from the
 * "voting_question" route parameter so the admin never has to pick it
 * manually from a list, and the option is guaranteed to be attached to the
 * question they were already looking at.
 */
class VotingOptionForm extends ContentEntityForm {

  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    protected readonly RouteMatchInterface $currentRouteMatch,
  ) {
    parent::__construct($entity_type_manager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('current_route_match'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildForm($form, $form_state);

    /** @var \Drupal\simple_voting\Entity\VotingOption $entity */
    $entity = $this->entity;
    if ($entity->isNew()) {
      $question = $this->currentRouteMatch->getParameter('voting_question');
      if ($question instanceof VotingQuestion) {
        $entity->set('question', $question->id());
      }
    }

    // The parent question is fixed by the route/context, not something the
    // admin should be re-selecting on this form.
    if (isset($form['question'])) {
      $form['question']['#access'] = FALSE;
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    /** @var \Drupal\simple_voting\Entity\VotingOption $entity */
    $entity = $this->entity;
    $result = parent::save($form, $form_state);

    $this->messenger()->addStatus($this->t('Option %title has been saved.', ['%title' => $entity->getTitle()]));
    $form_state->setRedirect('entity.voting_question.edit_form', ['voting_question' => $entity->getQuestionId()]);

    return $result;
  }

}
