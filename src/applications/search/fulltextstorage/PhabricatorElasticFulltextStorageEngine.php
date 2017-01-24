<?php

final class PhabricatorElasticFulltextStorageEngine
  extends PhabricatorFulltextStorageEngine {

  private $uri;
  private $index;
  private $timeout;
  private $version;
  private $timestampFieldKey;
  private $enabled = false;
  private $tag_cache = array();

  public function __construct() {
    $this->uri = PhabricatorEnv::getEnvConfig('search.elastic.host');
    $this->index = PhabricatorEnv::getEnvConfig('search.elastic.namespace');
    $this->version = (int)PhabricatorEnv::getEnvConfig(
                               'search.elastic.version');
    $this->timestampFieldKey = $this->version < 2
                               ? '_timestamp'
                               : 'lastModified';

    $this->enabled = PhabricatorEnv::getEnvConfigIfExists(
                               'search.elastic.enabled', false);
    if(isset($_REQUEST['elastic'])) {
      $this->enabled = true;
    }
  }

  public function getEngineIdentifier() {
    return 'elasticsearch';
  }

  public function getEnginePriority() {
    return 10;
  }

  public function isEnabled() {
    return $this->enabled;
  }

  public function setURI($uri) {
    $this->uri = $uri;
    return $this;
  }

  public function setIndex($index) {
    $this->index = $index;
    return $this;
  }

  public function setTimeout($timeout) {
    $this->timeout = $timeout;
    return $this;
  }

  public function getURI() {
    return $this->uri;
  }

  public function getIndex() {
    return $this->index;
  }

  public function getTimeout() {
    return $this->timeout;
  }

  static function boolterm($must=array(), $should=array(), $filter=array(),
    $must_not=array()) {
    $terms = func_get_args();
    return array('bool' => $terms);
  }

  protected function resolveTags($tags) {
    $lookup_phids = array();
    foreach($tags as $phid){
      if (!isset($this->tag_cache[$phid])) {
        $lookup_phids[]=$phid;
      }
    }
    if (count($lookup_phids)) {
      $projects = id(new PhabricatorProjectQuery())
        ->setViewer(PhabricatorUser::getOmnipotentUser())
        ->withPHIDs($lookup_phids)
        ->needSlugs(true)
        ->execute();

      foreach ($projects as $project) {
        $phid = $project->getPHID();
        $slugs = $project->getSlugs();
        $slugs = mpull($slugs, 'getSlug');
        $keywords = $project->getDisplayName() . ' ' . join(' ', $slugs);
        $keywords = strtolower($keywords);
        $keywords = str_replace('_', ' ', $keywords);
        $keywords = explode(' ', $keywords);
        $keywords = array_unique($keywords);
        $this->tag_cache[$phid] = $keywords;
      }
    }

    $keywords = array();
    foreach($tags as $phid) {
      if (isset($this->tag_cache[$phid])) {
        $keywords += $this->tag_cache[$phid];
      }
    }
    $keywords = array_unique($keywords);
    // phlog($result);
    return join(' ', $keywords);
  }

  public function getRelationshipTypes() {
    static $relationships = null;
    if (!empty($relationships)) {
      return $relationships;
    }

    $relationship_class = new ReflectionClass("PhabricatorSearchRelationship");
    $relationships = $relationship_class->getConstants();
    return array_unique(array_values($relationships));
  }

  public function reindexAbstractDocument(
    PhabricatorSearchAbstractDocument $doc) {

    $type = $doc->getDocumentType();
    $phid = $doc->getPHID();
    $handle = id(new PhabricatorHandleQuery())
      ->setViewer(PhabricatorUser::getOmnipotentUser())
      ->withPHIDs(array($phid))
      ->executeOne();

    $timestamp_key = $this->timestampFieldKey;

    // URL is not used internally but it can be useful externally.
    $spec = array(
      'title'         => $doc->getDocumentTitle(),
      'url'           => PhabricatorEnv::getProductionURI($handle->getURI()),
      'dateCreated'   => $doc->getDocumentCreated(),
      $timestamp_key  => $doc->getDocumentModified(),
    );

    foreach ($doc->getFieldData() as $field) {
      list($field_name, $corpus, $aux) = $field;
      $spec[$field_name] = $corpus;
      $spec['field'][] = array('type' => $field_name, 'aux' => $aux);
    }

    $tags = array();

    foreach ($doc->getRelationshipData() as $relationship) {
      list($rtype, $to_phid, $to_type, $time) = $relationship;
      $spec[$rtype][] = $to_phid;
      if ($rtype == PhabricatorSearchRelationship::RELATIONSHIP_PROJECT) {
        $tags[] = $to_phid;
      }
    }

    if (!empty($tags)) {
      $spec['tags'] = $this->resolveTags($tags);
    }

    $this->executeRequest("/{$type}/{$phid}/", $spec, 'PUT');
  }

  public function reconstructDocument($phid) {
    $type = phid_get_type($phid);

    $response = $this->executeRequest("/{$type}/{$phid}", array());

    if (empty($response['exists'])) {
      return null;
    }

    $hit = $response['_source'];

    $doc = new PhabricatorSearchAbstractDocument();
    $doc->setPHID($phid);
    $doc->setDocumentType($response['_type']);
    $doc->setDocumentTitle($hit['title']);
    $doc->setDocumentCreated($hit['dateCreated']);
    $doc->setDocumentModified($hit[$this->timestampFieldKey]);

    foreach ($hit['field'] as $fdef) {
      $field_type = $fdef['type'];
      $doc->addField($field_type, $hit[$field_type], $fdef['aux']);
    }

    foreach ($hit['relationship'] as $rtype => $rships) {
      foreach ($rships as $rship) {
        $doc->addRelationship(
          $rtype,
          $rship['phid'],
          $rship['phidType'],
          $rship['when']);
      }
    }

    return $doc;
  }

  private function buildSpec(PhabricatorSavedQuery $query) {
    $spec = array();
    $must = array();
    $should = array();
    $filter = array();

    if (strlen($query->getParameter('query'))) {
      $must[] = array(
        'simple_query_string' => array(
          'query'  => $query->getParameter('query'),
          'fields' => array(
            'title^3',
            'body^2',
            'tags',
          ),
          "default_operator" => "and",
        ),
      );
    }

    $exclude = $query->getParameter('exclude');
    if ($exclude) {
      $filter[] = array(
        'not' => array(
          'ids' => array(
            'values' => array($exclude),
          ),
        ),
      );
    }

    $relationship_map = array(
      PhabricatorSearchRelationship::RELATIONSHIP_AUTHOR =>
        $query->getParameter('authorPHIDs', array()),
      PhabricatorSearchRelationship::RELATIONSHIP_SUBSCRIBER =>
        $query->getParameter('subscriberPHIDs', array()),
      PhabricatorSearchRelationship::RELATIONSHIP_PROJECT =>
        $query->getParameter('projectPHIDs', array()),
      PhabricatorSearchRelationship::RELATIONSHIP_REPOSITORY =>
        $query->getParameter('repositoryPHIDs', array()),
    );

    $statuses = $query->getParameter('statuses', array());
    $statuses = array_fuse($statuses);

    $rel_open = PhabricatorSearchRelationship::RELATIONSHIP_OPEN;
    $rel_closed = PhabricatorSearchRelationship::RELATIONSHIP_CLOSED;
    $rel_unowned = PhabricatorSearchRelationship::RELATIONSHIP_UNOWNED;

    $include_open = !empty($statuses[$rel_open]);
    $include_closed = !empty($statuses[$rel_closed]);

    if ($include_open && !$include_closed) {
      $relationship_map[$rel_open] = true;
    } else if (!$include_open && $include_closed) {
      $relationship_map[$rel_closed] = true;
    }

    if ($query->getParameter('withUnowned')) {
      $relationship_map[$rel_unowned] = true;
    }

    $rel_owner = PhabricatorSearchRelationship::RELATIONSHIP_OWNER;
    if ($query->getParameter('withAnyOwner')) {
      $relationship_map[$rel_owner] = true;
    } else {
      $owner_phids = $query->getParameter('ownerPHIDs', array());
      $relationship_map[$rel_owner] = $owner_phids;
    }

    foreach ($relationship_map as $field => $phids) {
      if (is_array($phids) && $phids) {
        $filter[] = array(
          'terms' => array(
            $field  => array_values($phids),
          ),
        );
      } else if ($phids === true) {
        $filter[] = array(
          'exists' => array(
            'field' => $field,
          ),
        );
      }
    }

    if (!count($must)) {
      $must[] = array( "match_all" => array() );
    }

    $spec = array(
      '_source' => false,
      'query'   => self::boolterm($must, $should, $filter)
    );

    if (!$query->getParameter('query')) {
      $spec['sort'] = array(
        array('dateCreated' => 'desc'),
      );
    }

    $spec['from'] = (int)$query->getParameter('offset', 0);
    $spec['size'] = (int)$query->getParameter('limit', 25);
    phlog(json_encode($spec));
    return $spec;
  }

  public function executeSearch(PhabricatorSavedQuery $query) {
    $types = $query->getParameter('types');
    if (!$types) {
      $types = array_keys(
        PhabricatorSearchApplicationSearchEngine::getIndexableDocumentTypes());
    }

    // Don't use '/_search' for the case that there is something
    // else in the index (for example if 'phabricator' is only an alias to
    // some bigger index). Use '/$types/_search' instead.
    $uri = '/'.implode(',', $types).'/_search';

    try {
      $response = $this->executeRequest($uri, $this->buildSpec($query));
    } catch (HTTPFutureHTTPResponseStatus $ex) {
      // elasticsearch probably uses Lucene query syntax:
      // http://lucene.apache.org/core/3_6_1/queryparsersyntax.html
      // Try literal search if operator search fails.
      if (!strlen($query->getParameter('query'))) {
        throw $ex;
      }
      $query = clone $query;
      $query->setParameter(
        'query',
        addcslashes(
          $query->getParameter('query'), '+-&|!(){}[]^"~*?:\\'));
      $response = $this->executeRequest($uri, $this->buildSpec($query));
    }

    $phids = ipull($response['hits']['hits'], '_id');
    return $phids;
  }

  public function indexExists() {
    try {
      if ((int)$this->version >= 2) {
        return (bool)$this->executeRequest('/', array(), 'GET');
      } else {
        return (bool)$this->executeRequest('/_status/', array());
      }
    } catch (HTTPFutureHTTPResponseStatus $e) {
      if ($e->getStatusCode() == 404) {
        return false;
      }
      throw $e;
    }
  }

  private function getIndexConfiguration() {
    $data = array();
    $data['settings'] = array(
      'index' => array(
        'auto_expand_replicas' => '0-2',
        'analysis' => array(
          'filter' => array(
            'trigrams_filter' => array(
              'min_gram' => 3,
              'type' => 'ngram',
              'max_gram' => 3,
            ),
          ),
          'analyzer' => array(
            'custom_trigrams' => array(
              'type' => 'custom',
              'filter' => array(
                'lowercase',
                'kstem',
                'trigrams_filter',
              ),
              'tokenizer' => 'standard',
            ),
            "english_exact" => array(
              "tokenizer" => "standard",
              "filter"    => array(
                "lowercase"
              )
            ),
          ),
        ),
      ),
    );

    $relationships = $this->getRelationshipTypes();

    $types = array_keys(
      PhabricatorSearchApplicationSearchEngine::getIndexableDocumentTypes());

    foreach ($types as $type) {
      foreach (array('title', 'body') as $field) {
        // Use the custom analyzer for the corpus of text
        $data['mappings'][$type]['properties'][$field] = array(
          'type'      => 'string',
          'analyzer'  => 'english',
          'fields' => array(
            "exact" => array(
              "type"      => "string",
              "analyzer"  => "english_exact",
            )
          )
        );
      }

      foreach($relationships as $rel) {
        $data['mappings'][$type]['properties'][$rel] = array(
          'type'  => 'string',
          'index' => 'not_analyzed'
        );
      }

      // Ensure we have dateCreated since the default query requires it
      $data['mappings'][$type]['properties']['dateCreated']['type'] = 'date';

      // Replaces deprecated _timestamp for elasticsearch 2
      if ((int)$this->version >= 2) {
        $data['mappings'][$type]['properties']['lastModified']['type'] = 'date';
      }

      $data['mappings'][$type]['properties']['tags'] = array(
        'type' => 'string',
        'analyzer' => 'english',
        'store' => true,
      );
    }

    return $data;
  }

  public function indexIsSane() {
    if (!$this->indexExists()) {
      return false;
    }

    $cur_mapping = $this->executeRequest('/_mapping/', array());
    $cur_settings = $this->executeRequest('/_settings/', array());
    $actual = array_merge($cur_settings[$this->index],
      $cur_mapping[$this->index]);

    return $this->check($actual, $this->getIndexConfiguration());
  }

  /**
   * Recursively check if two Elasticsearch configuration arrays are equal
   *
   * @param $actual
   * @param $required array
   * @return bool
   */
  private function check($actual, $required) {
    foreach ($required as $key => $value) {
      if (!array_key_exists($key, $actual)) {
        if ($key === '_all') {
          // The _all field never comes back so we just have to assume it
          // is set correctly.
          continue;
        }
        return false;
      }
      if (is_array($value)) {
        if (!is_array($actual[$key])) {
          return false;
        }
        if (!$this->check($actual[$key], $value)) {
          return false;
        }
        continue;
      }

      $actual[$key] = self::normalizeConfigValue($actual[$key]);
      $value = self::normalizeConfigValue($value);
      if ($actual[$key] != $value) {
        return false;
      }
    }
    return true;
  }

  /**
   * Normalize a config value for comparison. Elasticsearch accepts all kinds
   * of config values but it tends to throw back 'true' for true and 'false' for
   * false so we normalize everything. Sometimes, oddly, it'll throw back false
   * for false....
   *
   * @param mixed $value config value
   * @return mixed value normalized
   */
  private static function normalizeConfigValue($value) {
    if ($value === true) {
      return 'true';
    } else if ($value === false) {
      return 'false';
    }
    return $value;
  }

  public function initIndex() {
    if ($this->indexExists()) {
      $this->executeRequest('/', array(), 'DELETE');
    }
    $data = $this->getIndexConfiguration();
    $this->executeRequest('/', $data, 'PUT');
  }

  private function executeRequest($path, array $data, $method = 'GET') {
    $uri = new PhutilURI($this->uri);
    $uri->setPath($this->index);
    $uri->appendPath($path);
    $data = json_encode($data);
    $profiler = PhutilServiceProfiler::getInstance();
    $profilerCallID = $profiler->beginServiceCall(
      array(
        'type' => 'http',
        'uri' => $data,
      ));
    $future = new HTTPSFuture($uri, $data);
    if ($method != 'GET') {
      $future->setMethod($method);
    }
    if ($this->getTimeout()) {
      $future->setTimeout($this->getTimeout());
    }
    list($body) = $future->resolvex();

    if ($method != 'GET') {
      return null;
    }
    $profiler->endServiceCall($profilerCallID, array());
    try {
      return phutil_json_decode($body);
    } catch (PhutilJSONParserException $ex) {
      throw new PhutilProxyException(
        pht('ElasticSearch server returned invalid JSON!'),
        $ex);
    }
  }

}
