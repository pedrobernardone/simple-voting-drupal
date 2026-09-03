<?php

declare(strict_types=1);

namespace Drupal\simple_voting\Controller;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\simple_voting\Entity\VotingOption;
use Drupal\simple_voting\Entity\VotingQuestion;
use Drupal\simple_voting\Service\VoteService;
use Drupal\simple_voting\Service\VotingException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Manual REST endpoints for the external voting API.
 *
 * Per the project requirements, these endpoints are implemented by hand
 * (plain Symfony request/response objects, hand-built JSON payloads) rather
 * than through JSON:API or the Serializer component. Authentication is
 * handled by Drupal\simple_voting\Authentication\Provider\ApiKeyAuth, wired
 * in via the routing "_auth" requirement, so these methods only deal with
 * the voting domain logic and never see the raw API key.
 */
class VotingApiController implements ContainerInjectionInterface {

  public function __construct(
    protected readonly VoteService $voteService,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self($container->get('simple_voting.vote_service'));
  }

  /**
   * GET /api/v1/voting/questions
   *
   * Lists the questions currently available for voting.
   */
  public function listQuestions(): JsonResponse {
    $questions = $this->voteService->getQuestions(onlyActive: TRUE);

    $data = array_map(fn (VotingQuestion $question) => $this->serializeQuestionSummary($question), $questions);

    return new JsonResponse(['data' => array_values($data)]);
  }

  /**
   * GET /api/v1/voting/questions/{voting_question}
   *
   * Shows a single question with its answer options, so the caller can
   * render a voting form.
   */
  public function showQuestion(VotingQuestion $voting_question): JsonResponse {
    if (!$voting_question->isActive()) {
      return $this->errorResponse('question_inactive', 'This question is not currently open for voting.', 404);
    }

    return new JsonResponse(['data' => $this->serializeQuestionDetail($voting_question)]);
  }

  /**
   * POST /api/v1/voting/questions/{voting_question}/votes
   *
   * Registers a vote cast by an external application on behalf of one of
   * its own users. Expected JSON body:
   * {"option_id": 3, "external_user_id": "abc-123"}.
   */
  public function castVote(Request $request, VotingQuestion $voting_question): JsonResponse {
    $payload = json_decode($request->getContent(), TRUE);
    if (!is_array($payload)) {
      return $this->errorResponse('invalid_payload', 'The request body must be a valid JSON object.', 400);
    }

    $optionId = $payload['option_id'] ?? NULL;
    $externalUserId = $payload['external_user_id'] ?? NULL;

    if (!is_numeric($optionId) || !is_string($externalUserId) || trim($externalUserId) === '') {
      return $this->errorResponse(
        'invalid_payload',
        'The request body must include a numeric "option_id" and a non-empty "external_user_id".',
        400,
      );
    }

    try {
      $vote = $this->voteService->castVoteForExternalUser((int) $voting_question->id(), (int) $optionId, (string) $externalUserId);
    }
    catch (VotingException $e) {
      return $this->errorResponse($e->reason, $e->getMessage(), $this->statusCodeForVotingException($e->reason));
    }

    return new JsonResponse([
      'data' => [
        'vote_id' => (int) $vote->id(),
        'question_id' => $vote->getQuestionId(),
        'option_id' => $vote->getOptionId(),
      ],
    ], 201);
  }

  /**
   * GET /api/v1/voting/questions/{voting_question}/results
   *
   * Returns aggregated results, respecting the per-question "show results"
   * setting configured by the administrator.
   */
  public function showResults(VotingQuestion $voting_question): JsonResponse {
    if (!$voting_question->shouldShowResults()) {
      return $this->errorResponse('results_hidden', 'Results are not published for this question.', 403);
    }

    $results = $this->voteService->getResults($voting_question);
    $total = array_sum($results);

    $options = [];
    foreach ($this->voteService->getOptions($voting_question) as $option) {
      $optionId = (int) $option->id();
      $options[] = [
        'option_id' => $optionId,
        'title' => $option->getTitle(),
        'votes' => $results[$optionId] ?? 0,
      ];
    }

    return new JsonResponse([
      'data' => [
        'question_id' => (int) $voting_question->id(),
        'total_votes' => $total,
        'options' => $options,
      ],
    ]);
  }

  /**
   * Builds the compact representation used in the questions listing.
   */
  protected function serializeQuestionSummary(VotingQuestion $question): array {
    return [
      'id' => (int) $question->id(),
      'title' => $question->getTitle(),
      'show_results' => $question->shouldShowResults(),
    ];
  }

  /**
   * Builds the detailed representation, including answer options.
   */
  protected function serializeQuestionDetail(VotingQuestion $question): array {
    $data = $this->serializeQuestionSummary($question);
    $data['description'] = $question->getDescription();
    $data['options'] = array_values(array_map(
      fn (VotingOption $option) => $this->serializeOption($option),
      $this->voteService->getOptions($question),
    ));

    return $data;
  }

  /**
   * Builds the representation of a single answer option.
   */
  protected function serializeOption(VotingOption $option): array {
    $imageUrl = NULL;
    if (!$option->get('image')->isEmpty()) {
      $file = $option->get('image')->entity;
      if ($file) {
        $imageUrl = \Drupal::service('file_url_generator')->generateAbsoluteString($file->getFileUri());
      }
    }

    return [
      'id' => (int) $option->id(),
      'title' => $option->getTitle(),
      'description' => $option->getDescription(),
      'image_url' => $imageUrl,
    ];
  }

  /**
   * Builds a standardized JSON error payload.
   */
  protected function errorResponse(string $code, string $message, int $status): JsonResponse {
    return new JsonResponse([
      'error' => [
        'code' => $code,
        'message' => $message,
      ],
    ], $status);
  }

  /**
   * Maps a VotingException reason to the appropriate HTTP status code.
   */
  protected function statusCodeForVotingException(string $reason): int {
    return match ($reason) {
      VotingException::QUESTION_NOT_FOUND, VotingException::OPTION_NOT_FOUND => 404,
      VotingException::VOTING_DISABLED, VotingException::QUESTION_INACTIVE => 403,
      VotingException::ALREADY_VOTED, VotingException::OPTION_MISMATCH => 409,
      VotingException::INVALID_VOTER => 400,
      default => 400,
    };
  }

}
