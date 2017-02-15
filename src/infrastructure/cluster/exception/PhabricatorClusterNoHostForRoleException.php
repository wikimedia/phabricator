<?php

class PhabricatorClusterNoHostForRoleException
  extends Exception {

  private $role;

  public function __construct($role) {
    $this->role = $role;
  }

  public function getExceptionTitle() {
    return pht('Search cluster has no hosts for role "%s"', $this->role);
  }

}
