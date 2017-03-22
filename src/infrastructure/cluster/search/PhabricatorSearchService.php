<?php

class PhabricatorSearchService
  extends Phobject {

  const KEY_REFS = 'cluster.search.refs';

  protected $config;
  protected $disabled;
  protected $engine;
  protected $hosts = array();
  protected $hostsConfig;
  protected $hostType;
  protected $roles = array();

  const STATUS_OKAY = 'okay';
  const STATUS_FAIL = 'fail';

  public function __construct(PhabricatorFulltextStorageEngine $engine) {
    $this->engine = $engine;
    $this->hostType = $engine->getHostType();
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

  public function getEngine() {
    return $this->engine;
  }

  public function getDisplayName() {
    return $this->hostType->getDisplayName();
  }

  public function getStatusViewColumns() {
    return $this->hostType->getStatusViewColumns();
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

  public function getConfig() {
    return $this->config;
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
      if ($val === false && isset($this->roles[$role])) {
        unset($this->roles[$role]);
      } else {
        $this->roles[$role] = $val;
      }
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
   * @return PhabricatorSearchService[]
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

  /**
   * Load all valid PhabricatorFulltextStorageEngine subclasses
   */
  public static function loadAllFulltextStorageEngines() {
    return id(new PhutilClassMapQuery())
    ->setAncestorClass('PhabricatorFulltextStorageEngine')
    ->setUniqueMethod('getEngineIdentifier')
    ->execute();
  }

  /**
   * Create instances of PhabricatorSearchService based on configuration
   * @return PhabricatorSearchService[]
   */
  public static function newRefs() {
    $services = PhabricatorEnv::getEnvConfig('cluster.search');
    $engines = self::loadAllFulltextStorageEngines();
    $refs = array();

    foreach ($services as $config) {
      $engine = $engines[$config['type']];
      $cluster = new self($engine);
      $cluster->setConfig($config);
      $engine->setService($cluster);
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
    foreach (self::getAllServices() as $service) {
      $service->getEngine()->reindexAbstractDocument($doc);
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
    $exceptions = array();
    foreach ($services as $service) {
      $engine = $service->getEngine();
      // try all hosts until one succeeds
      try {
        $res = $engine->executeSearch($query);
        // return immediately if we get results without an exception
        return $res;
      } catch (Exception $ex) {
        $exceptions[] = $ex;
      }
    }
    throw new PhutilAggregateException('All search engines failed:',
      $exceptions);
  }

}
