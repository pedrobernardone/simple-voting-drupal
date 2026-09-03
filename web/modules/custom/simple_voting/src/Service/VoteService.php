<?php

declare(strict_types=1);

namespace Drupal\simple_voting\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\simple_voting\Entity\VotingOption;
use Drupal\simple_voting\Entity\VotingQuestion;
use Drupal\simple_voting\Entity\VotingVote;

/**
 * Central service for the voting business logic.
 *
 * This is the single place that knows how to validate a vote attempt, how to
 * persist it, and how to compute results. Both the CMS controller and the
 * external API controllers delegate to it, so the two surfaces can never
 * drift apart in behavior.
 */
class VoteService {

  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly VotingSettings $votingSettings,
    protected readonly Connection $database,
  ) {}

  /**
   * Loads an active, votable question or throws.
   *
   * @throws \Drupal\simple_voting\Service\VotingException
   */
  public function getVotableQuestion(int $questionId): VotingQuestion {
    if ($this->votingSettings->isVotingDisabled()) {
      throw new VotingException(VotingException::VOTING_DISABLED, 'Voting has been disabled by the administrator.');
    }

    $question = $this->entityTypeManager->getStorage('voting_question')->load($questionId);
    if (!$question instanceof VotingQuestion) {
      throw new VotingException(VotingException::QUESTION_NOT_FOUND, 'The requested question does not exist.');
    }

    if (!$question->isActive()) {
      throw new VotingException(VotingException::QUESTION_INACTIVE, 'This question is not currently open for voting.');
    }

    return $question;
  }

  /**
   * Returns all questions, optionally filtering to only active ones.
   *
   * @return \Drupal\simple_voting\Entity\VotingQuestion[]
   */
  public function getQuestions(bool $onlyActive = TRUE): array {
    $storage = $this->entityTypeManager->getStorage('voting_question');
    $query = $storage->getQuery()->accessCheck(FALSE)->sort('created', 'DESC');
    if ($onlyActive) {
      $query->condition('status', TRUE);
    }
    $ids = $query->execute();

    return $ids ? $storage->loadMultiple($ids) : [];
  }

  /**
   * Returns the answer options belonging to a question, ordered by weight.
   *
   * @return \Drupal\simple_voting\Entity\VotingOption[]
   */
  public function getOptions(VotingQuestion $question): array {
    $storage = $this->entityTypeManager->getStorage('voting_option');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('question', $question->id())
      ->sort('weight', 'ASC')
      ->sort('id', 'ASC')
      ->execute();

    return $ids ? $storage->loadMultiple($ids) : [];
  }

  /**
   * Checks whether the given voter has already voted on the given question.
   */
  public function hasVoted(int $questionId, string $voterKey): bool {
    $storage = $this->entityTypeManager->getStorage('voting_vote');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('question', $questionId)
      ->condition('voter_key', $voterKey)
      ->range(0, 1)
      ->execute();

    return !empty($ids);
  }

  /**
   * Casts a vote cast from within the CMS by an authenticated user.
   *
   * @throws \Drupal\simple_voting\Service\VotingException
   */
  public function castVoteForUser(int $questionId, int $optionId, int $uid): VotingVote {
    if ($uid <= 0) {
      throw new VotingException(VotingException::INVALID_VOTER, 'An authenticated user is required to vote.');
    }

    return $this->castVote($questionId, $optionId, VotingVote::buildVoterKeyForUser($uid), $uid, NULL);
  }

  /**
   * Casts a vote coming from the external API on behalf of an external user.
   *
   * @throws \Drupal\simple_voting\Service\VotingException
   */
  public function castVoteForExternalUser(int $questionId, int $optionId, string $externalUserId): VotingVote {
    $externalUserId = trim($externalUserId);
    if ($externalUserId === '') {
      throw new VotingException(VotingException::INVALID_VOTER, 'An external user identifier is required to vote.');
    }

    return $this->castVote($questionId, $optionId, VotingVote::buildVoterKeyForExternalUser($externalUserId), NULL, $externalUserId);
  }

  /**
   * Shared vote-casting logic.
   *
   * Validates the question/option pair, then relies on the unique database
   * key on (question, voter_key) as the final authority against duplicate
   * votes: even if two requests race past the earlier `hasVoted()` check,
   * only one INSERT can succeed.
   *
   * @throws \Drupal\simple_voting\Service\VotingException
   */
  protected function castVote(int $questionId, int $optionId, string $voterKey, ?int $uid, ?string $externalUserId): VotingVote {
    $question = $this->getVotableQuestion($questionId);

    $optionStorage = $this->entityTypeManager->getStorage('voting_option');
    $option = $optionStorage->load($optionId);
    if (!$option instanceof VotingOption) {
      throw new VotingException(VotingException::OPTION_NOT_FOUND, 'The requested answer option does not exist.');
    }
    if ($option->getQuestionId() !== (int) $question->id()) {
      throw new VotingException(VotingException::OPTION_MISMATCH, 'The requested option does not belong to this question.');
    }

    if ($this->hasVoted((int) $question->id(), $voterKey)) {
      throw new VotingException(VotingException::ALREADY_VOTED, 'This voter has already voted on this question.');
    }

    $voteStorage = $this->entityTypeManager->getStorage('voting_vote');
    $vote = $voteStorage->create([
      'question' => $question->id(),
      'option' => $option->id(),
      'voter_key' => $voterKey,
      'uid' => $uid,
      'external_user_id' => $externalUserId,
    ]);

    try {
      $vote->save();
    }
    catch (EntityStorageException $e) {
      // A unique-key violation here means a race lost against another
      // concurrent request for the same voter/question: treat it the same
      // as an already-voted error instead of leaking a database exception.
      if ($this->hasVoted((int) $question->id(), $voterKey)) {
        throw new VotingException(VotingException::ALREADY_VOTED, 'This voter has already voted on this question.');
      }
      throw $e;
    }

    return $vote;
  }

  /**
   * Computes the vote totals per option for a question.
   *
   * @return array<int, int>
   *   Vote counts keyed by option id.
   */
  public function getResults(VotingQuestion $question): array {
    $query = $this->database->select('voting_vote', 'v')
      ->fields('v', ['option'])
      ->condition('v.question', $question->id())
      ->groupBy('v.option');
    $query->addExpression('COUNT(v.id)', 'total');
    $rows = $query->execute()->fetchAllKeyed(0, 1);

    $results = [];
    foreach ($this->getOptions($question) as $option) {
      $optionId = (int) $option->id();
      $results[$optionId] = isset($rows[$optionId]) ? (int) $rows[$optionId] : 0;
    }

    return $results;
  }

}
