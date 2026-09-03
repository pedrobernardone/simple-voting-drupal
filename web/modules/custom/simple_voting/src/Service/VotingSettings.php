<?php

declare(strict_types=1);

namespace Drupal\simple_voting\Service;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Provides access to the global simple_voting settings.
 */
class VotingSettings {

  public function __construct(
    protected readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Whether voting has been disabled globally by the administrator.
   *
   * When TRUE, the entire voting flow must be blocked, both in the CMS and
   * for the external API.
   */
  public function isVotingDisabled(): bool {
    return (bool) $this->configFactory->get('simple_voting.settings')->get('voting_disabled');
  }

}
