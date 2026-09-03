<?php

declare(strict_types=1);

namespace Drupal\Tests\simple_voting\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\simple_voting\Service\VotingException;

/**
 * Tests the core voting business rules enforced by VoteService.
 *
 * @group simple_voting
 * @coversDefaultClass \Drupal\simple_voting\Service\VoteService
 */
class VoteServiceTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'text',
    'file',
    'image',
    'simple_voting',
  ];

  /**
   * The service under test.
   */
  protected $voteService;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('voting_question');
    $this->installEntitySchema('voting_option');
    $this->installEntitySchema('voting_vote');
    $this->installEntitySchema('user');
    $this->installConfig(['simple_voting']);

    $this->voteService = $this->container->get('simple_voting.vote_service');
  }

  /**
   * Creates a question with two answer options for use in the tests.
   *
   * @return array
   *   [$question, $option1, $option2].
   */
  protected function createQuestionWithOptions(): array {
    $questionStorage = $this->container->get('entity_type.manager')->getStorage('voting_question');
    $optionStorage = $this->container->get('entity_type.manager')->getStorage('voting_option');

    $question = $questionStorage->create([
      'title' => 'Test question',
      'status' => TRUE,
      'show_results' => TRUE,
    ]);
    $question->save();

    $option1 = $optionStorage->create([
      'question' => $question->id(),
      'title' => 'Option A',
    ]);
    $option1->save();

    $option2 = $optionStorage->create([
      'question' => $question->id(),
      'title' => 'Option B',
    ]);
    $option2->save();

    return [$question, $option1, $option2];
  }

  /**
   * A user can cast exactly one vote on a question.
   */
  public function testCastVoteForUserSucceeds(): void {
    [$question, $option1] = $this->createQuestionWithOptions();

    $vote = $this->voteService->castVoteForUser((int) $question->id(), (int) $option1->id(), 42);

    $this->assertSame((int) $question->id(), $vote->getQuestionId());
    $this->assertSame((int) $option1->id(), $vote->getOptionId());
    $this->assertTrue($this->voteService->hasVoted((int) $question->id(), 'user:42'));
  }

  /**
   * The same user cannot vote twice on the same question.
   */
  public function testDuplicateVoteByUserIsRejected(): void {
    [$question, $option1, $option2] = $this->createQuestionWithOptions();

    $this->voteService->castVoteForUser((int) $question->id(), (int) $option1->id(), 42);

    $this->expectException(VotingException::class);
    $this->voteService->castVoteForUser((int) $question->id(), (int) $option2->id(), 42);
  }

  /**
   * The same external user cannot vote twice on the same question.
   */
  public function testDuplicateVoteByExternalUserIsRejected(): void {
    [$question, $option1, $option2] = $this->createQuestionWithOptions();

    $this->voteService->castVoteForExternalUser((int) $question->id(), (int) $option1->id(), 'ext-user-1');

    try {
      $this->voteService->castVoteForExternalUser((int) $question->id(), (int) $option2->id(), 'ext-user-1');
      $this->fail('Expected a VotingException to be thrown.');
    }
    catch (VotingException $e) {
      $this->assertSame(VotingException::ALREADY_VOTED, $e->reason);
    }
  }

  /**
   * Different voters (internal and external) can vote independently.
   */
  public function testDifferentVotersCanEachVoteOnce(): void {
    [$question, $option1, $option2] = $this->createQuestionWithOptions();

    $this->voteService->castVoteForUser((int) $question->id(), (int) $option1->id(), 1);
    $this->voteService->castVoteForExternalUser((int) $question->id(), (int) $option2->id(), 'ext-user-1');

    $results = $this->voteService->getResults($question);
    $this->assertSame(1, $results[(int) $option1->id()]);
    $this->assertSame(1, $results[(int) $option2->id()]);
  }

  /**
   * Voting on an option that belongs to a different question is rejected.
   */
  public function testVotingWithMismatchedOptionIsRejected(): void {
    [$question1] = $this->createQuestionWithOptions();
    [, $option2FromOtherQuestion] = $this->createQuestionWithOptions();

    try {
      $this->voteService->castVoteForUser((int) $question1->id(), (int) $option2FromOtherQuestion->id(), 1);
      $this->fail('Expected a VotingException to be thrown.');
    }
    catch (VotingException $e) {
      $this->assertSame(VotingException::OPTION_MISMATCH, $e->reason);
    }
  }

  /**
   * Voting on an inactive question is rejected.
   */
  public function testVotingOnInactiveQuestionIsRejected(): void {
    [$question, $option1] = $this->createQuestionWithOptions();
    $question->set('status', FALSE)->save();

    try {
      $this->voteService->castVoteForUser((int) $question->id(), (int) $option1->id(), 1);
      $this->fail('Expected a VotingException to be thrown.');
    }
    catch (VotingException $e) {
      $this->assertSame(VotingException::QUESTION_INACTIVE, $e->reason);
    }
  }

  /**
   * The global kill switch blocks voting on any question.
   */
  public function testGlobalVotingDisabledBlocksVoting(): void {
    [$question, $option1] = $this->createQuestionWithOptions();

    $this->config('simple_voting.settings')->set('voting_disabled', TRUE)->save();

    try {
      $this->voteService->castVoteForUser((int) $question->id(), (int) $option1->id(), 1);
      $this->fail('Expected a VotingException to be thrown.');
    }
    catch (VotingException $e) {
      $this->assertSame(VotingException::VOTING_DISABLED, $e->reason);
    }
  }

  /**
   * getResults() returns zero counts for options with no votes yet.
   */
  public function testResultsIncludeOptionsWithZeroVotes(): void {
    [$question, $option1, $option2] = $this->createQuestionWithOptions();

    $this->voteService->castVoteForUser((int) $question->id(), (int) $option1->id(), 1);

    $results = $this->voteService->getResults($question);
    $this->assertSame(1, $results[(int) $option1->id()]);
    $this->assertSame(0, $results[(int) $option2->id()]);
  }

}
