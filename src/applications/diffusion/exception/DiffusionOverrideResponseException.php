<?php
final class DiffusionOverrideResponseException extends Exception {
  public function __construct(AphrontResponse $response) {
    $this->response = $response;
  }

  public function getResponse() {
    return $this->response;
  }
}
