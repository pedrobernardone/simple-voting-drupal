<?php

declare(strict_types=1);

namespace Drupal\simple_voting\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Render\Markup;
use Drupal\simple_voting\Entity\VotingOption;
use Drupal\simple_voting\Entity\VotingQuestion;
use Drupal\simple_voting\Entity\VotingVote;
use Drupal\simple_voting\Service\VoteService;
use Drupal\simple_voting\Service\VotingException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for the CMS-facing voting pages.
 *
 * This is the human-facing counterpart to VotingApiController: it renders
 * questions and options as normal Drupal pages and reuses the very same
 * VoteService for validation and persistence, so a vote cast here follows
 * exactly the same rules (single active question, single vote per user,
 * global kill switch) as a vote cast through the external API.
 */
class VotingPageController extends ControllerBase {

  public function __construct(
    protected readonly VoteService $voteService,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self($container->get('simple_voting.vote_service'));
  }

  /**
   * Lists the questions currently open for voting.
   */
  public function listQuestions(): array {
    $questions = $this->voteService->getQuestions(onlyActive: TRUE);
    $uid = (int) $this->currentUser()->id();

    $items = [];
    foreach ($questions as $question) {
      $voterKey = VotingVote::buildVoterKeyForUser($uid);
      $items[] = [
        '#type' => 'link',
        '#title' => $question->getTitle() . ($this->voteService->hasVoted((int) $question->id(), $voterKey) ? ' (' . $this->t('already voted') . ')' : ''),
        '#url' => \Drupal\Core\Url::fromRoute('simple_voting.question_vote', ['voting_question' => $question->id()]),
      ];
    }

    return [
      '#theme' => 'item_list',
      '#items' => $items,
      '#empty' => $this->t('There are no questions open for voting right now.'),
      '#title' => $this->t('Open questions'),
    ];
  }

  /**
   * Shows a single question: either the voting form or the results.
   */
  public function showQuestion(VotingQuestion $voting_question, Request $request): array {
    $build = [];
    // The rendered content depends on which user is looking at it (has this
    // specific user already voted?) and on the current vote tally, so this
    // page must never be served from a cache shared across users/requests.
    $build['#cache'] = [
      'contexts' => ['user'],
      'tags' => ['voting_vote_list:' . $voting_question->id()],
      'max-age' => 0,
    ];

    if (!$voting_question->isActive()) {
      $build['message'] = ['#markup' => '<p>' . $this->t('This question is not currently open for voting.') . '</p>'];
      return $build;
    }

    $uid = (int) $this->currentUser()->id();
    $voterKey = VotingVote::buildVoterKeyForUser($uid);
    $alreadyVoted = $this->voteService->hasVoted((int) $voting_question->id(), $voterKey);

    $build['title'] = ['#markup' => '<h2>' . $voting_question->getTitle() . '</h2>'];
    if ($voting_question->getDescription() !== '') {
      $build['description'] = ['#markup' => '<p>' . $voting_question->getDescription() . '</p>'];
    }

    if ($this->votingDisabled()) {
      $build['message'] = ['#markup' => '<p>' . $this->t('Voting has been temporarily disabled by the administrator.') . '</p>'];
    }
    elseif ($alreadyVoted) {
      $build['message'] = ['#markup' => '<p>' . $this->t('You have already voted on this question.') . '</p>'];
    }
    else {
      $build['form'] = $this->buildVoteForm($voting_question);
    }

    if ($alreadyVoted && $voting_question->shouldShowResults()) {
      $build['results'] = $this->buildResults($voting_question);
    }

    return $build;
  }

  /**
   * Handles the vote submission posted from the voting form.
   */
  public function submitVote(Request $request, VotingQuestion $voting_question): RedirectResponse {
    $actionPath = $request->getPathInfo();
    $token = (string) $request->request->get('token');

    if (!\Drupal::csrfToken()->validate($token, $actionPath)) {
      $this->messenger()->addError($this->t('Your session has expired, please try again.'));
      return $this->redirect('simple_voting.question_vote', ['voting_question' => $voting_question->id()]);
    }

    $optionId = (int) $request->request->get('option_id');
    $account = $this->currentUser();

    try {
      $this->voteService->castVoteForUser((int) $voting_question->id(), $optionId, (int) $account->id());
      $this->messenger()->addStatus($this->t('Your vote has been registered.'));
    }
    catch (VotingException $e) {
      $this->messenger()->addError($this->votingExceptionMessage($e));
    }

    return $this->redirect('simple_voting.question_vote', ['voting_question' => $voting_question->id()]);
  }

  /**
   * Builds the plain HTML form used to submit a vote.
   */
  protected function buildVoteForm(VotingQuestion $question): array {
    $options = $this->voteService->getOptions($question);

    return [
      // The markup is built by renderVoteFormMarkup(), which escapes every
      // user-provided value (option titles) with htmlspecialchars(). Using
      // Markup::create() here is safe and necessary: plain '#markup' would
      // otherwise be passed through Xss::filter(), which strips <form> and
      // <input> tags and would silently break the voting form.
      '#markup' => Markup::create($this->renderVoteFormMarkup($question, $options)),
    ];
  }

  /**
   * Renders the vote submission form as plain HTML.
   *
   * @param \Drupal\simple_voting\Entity\VotingOption[] $options
   */
  protected function renderVoteFormMarkup(VotingQuestion $question, array $options): string {
    $actionUrl = \Drupal\Core\Url::fromRoute('simple_voting.question_vote_submit', ['voting_question' => $question->id()])->toString();

    $optionsMarkup = '';
    foreach ($options as $option) {
      $optionsMarkup .= sprintf(
        '<div><label><input type="radio" name="option_id" value="%d" required> %s</label></div>',
        $option->id(),
        htmlspecialchars($option->getTitle()),
      );
    }

    $token = \Drupal::csrfToken()->get($actionUrl);

    return sprintf(
      '<form method="post" action="%s">%s<input type="hidden" name="token" value="%s"><button type="submit">%s</button></form>',
      $actionUrl,
      $optionsMarkup,
      $token,
      $this->t('Vote'),
    );
  }

  /**
   * Builds a simple results table for a question.
   */
  protected function buildResults(VotingQuestion $question): array {
    $results = $this->voteService->getResults($question);
    $total = array_sum($results);

    $rows = [];
    foreach ($this->voteService->getOptions($question) as $option) {
      $optionId = (int) $option->id();
      $votes = $results[$optionId] ?? 0;
      $percentage = $total > 0 ? round(($votes / $total) * 100, 1) : 0;
      $rows[] = [$option->getTitle(), $votes, $percentage . '%'];
    }

    return [
      '#type' => 'table',
      '#header' => [$this->t('Option'), $this->t('Votes'), $this->t('Percentage')],
      '#rows' => $rows,
      '#empty' => $this->t('No votes yet.'),
    ];
  }

  /**
   * Whether the global kill switch is on.
   */
  protected function votingDisabled(): bool {
    return \Drupal::service('simple_voting.settings')->isVotingDisabled();
  }

  /**
   * Maps a VotingException to a user-facing message.
   */
  protected function votingExceptionMessage(VotingException $e): string {
    return match ($e->reason) {
      VotingException::ALREADY_VOTED => (string) $this->t('You have already voted on this question.'),
      VotingException::VOTING_DISABLED => (string) $this->t('Voting has been temporarily disabled by the administrator.'),
      VotingException::QUESTION_INACTIVE => (string) $this->t('This question is not currently open for voting.'),
      default => (string) $this->t('Your vote could not be registered.'),
    };
  }

}
