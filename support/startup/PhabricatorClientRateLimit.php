<?php

final class PhabricatorClientRateLimit
  extends PhabricatorClientLimit {

  protected $whitelist = array('87.138.110.76', '198.73.209.241');

  protected function getBucketDuration() {
    return 60;
  }

  protected function getBucketCount() {
    return 5;
  }

  protected function shouldRejectConnection($score) {
    $limit = $this->getLimit();

    // Reject connections if the average score across all buckets exceeds the
    // limit.
    $average_score = $score / $this->getBucketCount();

    if ($average_score <= $limit) {
      return false;
    }

    // don't reject whitelisted connections
    $key = $this->getClientKey();
    if (in_array($key, $this->whitelist)) {
      return false;
    }
    return true;
  }

  protected function getConnectScore() {
    return 0;
  }

  protected function getPenaltyScore() {
    return 0;
  }

  protected function getDisconnectScore(array $request_state) {
    $score = 1;

    $key = $this->getClientKey();
    // whitelisted ips get unlimited requests
    if (in_array($key, $this->whitelist)) {
      $score = 0;
    }

    if (isset($request_state['viewer'])) {
      $viewer = $request_state['viewer'];
      if ($viewer->isOmnipotent() || $viewer->getIsSystemAgent()) {
        // If the viewer was omnipotent, this was an intracluster request or
        // some other kind of special request, so don't give it any points
        // toward rate limiting.
        $score = 0;
      } else if ($viewer->isLoggedIn()) {
        // If the viewer was logged in, give them fewer points than if they
        // were logged out, since this traffic is much more likely to be
        // legitimate.
        $score = $score / 4;
      }
    }
    return $score;
  }

  protected function getRateLimitReason($score) {
    $client_key = $this->getClientKey();

    // NOTE: This happens before we load libraries, so we can not use pht()
    // here.

    return
      "TOO MANY REQUESTS\n".
      "You (\"{$client_key}\") are issuing too many requests ".
      "too quickly.\n";
  }

}
