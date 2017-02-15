<?php

final class PhabricatorMySQLSearchHost
  extends PhabricatorSearchHost {

  private $engine;

  public function __construct() {
    $this->engine = new PhabricatorMySQLFulltextStorageEngine();
  }

  public function setConfig($config) {
    $this->setRoles(idx($config, 'roles',
      array('read' => true, 'write' => true)));
    return $this;
  }

  public function getDisplayName() {
    return 'MySQL';
  }

  public function getEngineIdentifier() {
    return 'mysql';
  }

  public function getStatusViewColumns() {
    return array(
        pht('Protocol') => $this->getEngineIdentifier(),
        pht('Roles') => implode(', ', array_keys($this->getRoles())),
    );
  }

  public function getEngine() {
    return $this->engine;
  }

  public function getProtocol() {
    return 'mysql';
  }

  public function getConnectionStatus() {
    PhabricatorDatabaseRef::queryAll();
    $ref = PhabricatorDatabaseRef::getMasterDatabaseRefForApplication('search');
    $status = $ref->getConnectionStatus();
    return $status;
  }

}
