<?php

class PhabricatorSearchCluster
  extends Phobject {

  const KEY_REFS = 'cluster.search.refs';

  protected $roles = array();
  protected $disabled;
  protected $hosts = array();
  protected $hostsConfig;
  protected $hostType;
  protected $config;

  const STATUS_OKAY = 'okay';
  const STATUS_FAIL = 'fail';

  public function __construct($host_type) {
    $this->hostType = $host_type;
  }

  /**
   * @throws Exception
   */
  public function newHost($config) {
    $host = clone($this->hostType);
    $host_config = $this->config + $config;
    $host->setConfig($host_config);
    $this->hosts[] = $host;
    return $host;
  }

  public function getDisplayName() {
    return $this->hostType->getDisplayName();
  }

  public function setConfig($config) {
    $this->config = $config;

    if (!isset($config['hosts'])) {
      $config['hosts'] = array(
        array(
          'host' => idx($config, 'host'),
          'port' => idx($config, 'port'),
          'protocol' => idx($config, 'protocol'),
          'roles' => idx($config, 'roles'),
        ),
      );
    }
    foreach ($config['hosts'] as $host) {
      $this->newHost($host);
    }

  }

  public function setDisabled($disabled) {
    $this->disabled = $disabled;
    return $this;
  }

  public function getDisabled() {
    return $this->disabled;
  }

  public static function getConnectionStatusMap() {
    return array(
      self::STATUS_OKAY => array(
        'icon' => 'fa-exchange',
        'color' => 'green',
        'label' => pht('Okay'),
      ),
      self::STATUS_FAIL => array(
        'icon' => 'fa-times',
        'color' => 'red',
        'label' => pht('Failed'),
      ),
    );
  }

  public function isWritable() {
    return $this->hasRole('write');
  }

  public function isReadable() {
    return $this->hasRole('read');
  }

  public function hasRole($role) {
    return isset($this->roles[$role]) && $this->roles[$role] === true;
  }

  public function setRoles(array $roles) {
    foreach ($roles as $role => $val) {
      $this->roles[$role] = $val;
    }
    return $this;
  }

  public function getRoles() {
    return $this->roles;
  }

  public function getPort() {
    return idx($this->config, 'port');
  }

  public function getProtocol() {
    return idx($this->config, 'protocol');
  }


  public function getVersion() {
    return idx($this->config, 'version');
  }

  public function getHosts() {
    return $this->hosts;
  }


  /** @return PhabricatorSearchHost */
  public function getAnyHostForRole($role) {
    $hosts = $this->getAllHostsForRole($role);
    if (empty($hosts)) {
      throw new PhabricatorClusterNoHostForRoleException($role);
    }
    $random = array_rand($hosts);
    return $hosts[$random];
  }


  /** @return PhabricatorSearchHost[] */
  public function getAllHostsForRole($role) {
    $hosts = array();
    foreach ($this->hosts as $host) {
      if ($host->hasRole($role)) {
        $hosts[] = $host;
      }
    }
    return $hosts;
  }

  /**
   * Get a reference to all configured fulltext search cluster services
   * @return PhabricatorSearchCluster[]
   */
  public static function getAllServices() {
    $cache = PhabricatorCaches::getRequestCache();

    $refs = $cache->getKey(self::KEY_REFS);
    if (!$refs) {
      $refs = self::newRefs();
      $cache->setKey(self::KEY_REFS, $refs);
    }

    return $refs;
  }

  /** find one random writable host from each service.
   * @return PhabricatorSearchCluster[] writable cluster hosts
   */
  public static function getAllWritableHosts() {
    $services = self::getAllServices();
    $all_writable = array();
    foreach ($services as $service) {
      $all_writable += $service->getAllHostsForRole('write');
    }
    return $all_writable;
  }


  public static function getValidHostTypes() {
    return id(new PhutilClassMapQuery())
    ->setAncestorClass('PhabricatorSearchHost')
    ->setUniqueMethod('getEngineIdentifier')
    ->execute();
  }

  /**
   * Create instances of PhabricatorSearchCluster based on configuration
   * @return PhabricatorSearchCluster[]
   */
  public static function newRefs() {
    $services = PhabricatorEnv::getEnvConfig('cluster.search');
    $types = self::getValidHostTypes();
    $refs = array();

    foreach ($services as $config) {
      if (!isset($types[$config['type']])) {
        // this really should not happen as the value is validated by
        // PhabricatorClusterSearchConfigOptionType
        continue;
      }
      $type = $types[$config['type']];
      $cluster = new self($type);
      $cluster->setConfig($config);

      $refs[] = $cluster;
    }

    return $refs;
  }


  /**
   * (re)index the document: attempt to pass the document to all writable
   * fulltext search hosts
   */
  public static function reindexAbstractDocument(
    PhabricatorSearchAbstractDocument $doc) {

    $indexed = 0;
    foreach (self::getAllWritableHosts() as $host) {
      $host->getEngine()->reindexAbstractDocument($doc);
      $indexed++;
    }
    if ($indexed == 0) {
      throw new PhabricatorClusterNoHostForRoleException('write');
    }
  }

  /**
   * Execute a full-text query and return a list of PHIDs of matching objects.
   * @return string[]
   */
  public static function executeSearch(PhabricatorSavedQuery $query) {
    $services = self::getAllServices();
    foreach ($services as $service) {
      $hosts = $service->getAllHostsForRole('read');
      // try all hosts until one succeeds
      foreach ($hosts as $host) {
        $last_exception = null;
        try {
          $res = $host->getEngine()->executeSearch($query);
          // return immediately if we get results without an exception
          return $res;
        } catch (Exception $ex) {
          // try each server in turn, only throw if none succeed
          $last_exception = $ex;
        }
      }
    }
    if ($last_exception) {
      throw $last_exception;
    }
    return $res;
  }

}
