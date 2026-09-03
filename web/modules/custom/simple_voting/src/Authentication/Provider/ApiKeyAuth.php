<?php

declare(strict_types=1);

namespace Drupal\simple_voting\Authentication\Provider;

use Drupal\Core\Authentication\AuthenticationProviderInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\UserSession;
use Symfony\Component\HttpFoundation\Request;

/**
 * Authenticates external API requests using a static API key header.
 *
 * The key identifies the calling *application*, not an individual end user.
 * Which end user is voting is determined separately, from the
 * "external_user_id" the application sends in the request payload (see
 * VoteService). This keeps the external API simple to integrate against
 * (a single shared secret per application) while still letting the voting
 * system tell voters apart for the one-vote-per-question rule.
 */
class ApiKeyAuth implements AuthenticationProviderInterface {

  public const HEADER_NAME = 'X-API-Key';

  public function __construct(
    protected readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function applies(Request $request): bool {
    return $request->headers->has(self::HEADER_NAME);
  }

  /**
   * {@inheritdoc}
   */
  public function authenticate(Request $request): ?AccountInterface {
    $providedKey = (string) $request->headers->get(self::HEADER_NAME, '');
    $configuredKey = (string) $this->configFactory->get('simple_voting.settings')->get('api_key');

    if ($providedKey === '' || $configuredKey === '' || !hash_equals($configuredKey, $providedKey)) {
      return NULL;
    }

    // Represent the authenticated application as a session-less account with
    // the permissions granted to the dedicated "external voting API client"
    // role. No real Drupal user account is created or required per caller.
    return new UserSession([
      'uid' => 0,
      'roles' => [AccountInterface::ANONYMOUS_ROLE, 'external_voting_api_client'],
    ]);
  }

}
