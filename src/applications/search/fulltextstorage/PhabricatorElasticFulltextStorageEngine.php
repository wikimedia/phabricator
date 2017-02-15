<?php

final class PhabricatorElasticFulltextStorageEngine
  extends PhabricatorFulltextStorageEngine {

  private $ref;
  private $index;
  private $timeout;
  private $version;
  private $timestampFieldKey;
  private $textFieldType;
  private static $tagCache = array();

  public function setRef(PhabricatorElasticSearchHost $ref) {
    $this->ref = $ref;
    $this->index = str_replace('/', '', $ref->getPath());
    $this->version = (int)$ref->getVersion();

    $this->timestampFieldKey = $this->version < 2
                               ? '_timestamp'
                               : 'lastModified';

    $this->textFieldType = $this->version >= 5
                         ? 'text'
                         : 'string';
    return $this;
  }

  public function getEngineIdentifier() {
    return 'elasticsearch';
  }

  public function setTimeout($timeout) {
    $this->timeout = $timeout;
    return $this;
  }

  public function getURI($path = '') {
    return $this->ref->getURI($path);
  }

  public function getIndex() {
    return $this->index;
  }

  public function getTimeout() {
    return $this->timeout;
  }


  protected function resolveTags($tags) {

    $lookup_phids = array();
    foreach ($tags as $phid) {
      if (!isset(self::$tagCache[$phid])) {
        $lookup_phids[] = $phid;
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
        $keywords = $project->getDisplayName().' '.implode(' ', $slugs);
        $keywords = strtolower($keywords);
        $keywords = str_replace('_', ' ', $keywords);
        $keywords = explode(' ', $keywords);
        $keywords = array_unique($keywords);
        self::$tagCache[$phid] = $keywords;
      }
    }

    $keywords = array();
    foreach ($tags as $phid) {
      if (isset(self::$tagCache[$phid])) {
        $keywords += self::$tagCache[$phid];
      }
    }
    $keywords = array_unique($keywords);
    return implode(' ', $keywords);
  }

  public function getTypeConstants($class) {
    static $typeconstants = array();
    if (!empty($typeconstants[$class])) {
      return $typeconstants[$class];
    }

    $relationship_class = new ReflectionClass($class);
    $typeconstants[$class] = $relationship_class->getConstants();
    return array_unique(array_values($typeconstants[$class]));
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
      if (!isset($spec[$field_name])) {
        $spec[$field_name] = $corpus;
      } else if (!is_array($spec[$field_name])) {
        $spec[$field_name] = array($spec[$field_name], $corpus);
      } else {
        $spec[$field_name][] = $corpus;
      }
      if ($aux != null) {
        $spec[$field_name.'_aux_phid'] = $aux;
      }
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
    $q = new PhabricatorElasticSearchQueryBuilder('bool');
    $query_string = $query->getParameter('query');
    if (strlen($query_string)) {
      $fields = $this->getTypeConstants('PhabricatorSearchDocumentFieldType');

      $q->addMustClause(array(
        'simple_query_string' => array(
          'query'  => $query_string,
          'fields' => array(
            'title^4',
            'body^3',
            'cmnt^2',
            'tags',
            '_all',
          ),
          'default_operator' => 'and',
        ),
      ));

      $q->addShouldClause(array(
        'simple_query_string' => array(
          'query'  => $query_string,
          'fields' => array_values($fields),
          'analyzer' => 'english_exact',
          'default_operator' => 'and',
        ),
      ));

    }

    $exclude = $query->getParameter('exclude');
    if ($exclude) {
      $q->addFilterClause(array(
        'not' => array(
          'ids' => array(
            'values' => array($exclude),
          ),
        ),
      ));
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
      $q->addExistsClause($rel_open);
    } else if (!$include_open && $include_closed) {
      $q->addExistsClause($rel_closed);
    }

    if ($query->getParameter('withUnowned')) {
      $q->addExistsClause($rel_unowned);
    }

    $rel_owner = PhabricatorSearchRelationship::RELATIONSHIP_OWNER;
    if ($query->getParameter('withAnyOwner')) {
      $q->addExistsClause($rel_owner);
    } else {
      $owner_phids = $query->getParameter('ownerPHIDs', array());
      if (count($owner_phids)) {
        $q->addTermsClause($rel_owner, $owner_phids);
      }
    }

    foreach ($relationship_map as $field => $phids) {
      if (is_array($phids) && !empty($phids)) {
        $q->addTermsClause($field, $phids);
      }
    }

    if (!$q->getClauseCount('must')) {
      $q->addMustClause(array('match_all' => array('boost' => 1 )));
    }

    $spec = array(
      '_source' => false,
      'query' => array(
        'bool' => $q->toArray(),
      ),
    );


    if (!$query->getParameter('query')) {
      $spec['sort'] = array(
        array('dateCreated' => 'desc'),
      );
    }

    $offset = (int)$query->getParameter('offset', 0);
    $limit =  (int)$query->getParameter('limit', 101);
    if ($offset + $limit > 10000) {
      throw new Exception(pht(
        'Query offset is too large. offset+limit=%s (max=%s)',
        $offset + $limit,
        10000));
    }
    $spec['from'] = $offset;
    $spec['size'] = $limit;
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

    $spec = $this->buildSpec($query);
    $response = $this->executeRequest($uri, $spec);

    $phids = ipull($response['hits']['hits'], '_id');
    return $phids;
  }

  public function indexExists() {
    try {

      if ($this->version >= 5) {
        $uri = '/_stats/';
        $res = $this->executeRequest($uri, array());
        return isset($res['indices']['phabricator']);
      } else if ($this->version >= 2) {
        $uri = '';
      } else {
        $uri = '/_status/';
      }
      return (bool)$this->executeRequest($uri, array());
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
          'analyzer' => array(
            'english_exact' => array(
              'tokenizer' => 'standard',
              'filter'    => array('lowercase'),
            ),
          ),
        ),
      ),
    );

    $fields = $this->getTypeConstants('PhabricatorSearchDocumentFieldType');
    $relationships = $this->getTypeConstants('PhabricatorSearchRelationship');

    $types = array_keys(
      PhabricatorSearchApplicationSearchEngine::getIndexableDocumentTypes());

    foreach ($types as $type) {
      $properties = array();
      foreach ($fields as $field) {
        // Use the custom analyzer for the corpus of text
        $properties[$field] = array(
          'type'                  => $this->textFieldType,
          'analyzer'              => 'english_exact',
          'search_analyzer'       => 'english',
          'search_quote_analyzer' => 'english_exact',
        );
      }

      foreach ($relationships as $rel) {
        $properties[$rel] = array(
          'type'  => $this->textFieldType,
        );
        if ($this->version < 5) {
          $properties[$rel]['index'] = 'not_analyzed';
        }
      }

      // Ensure we have dateCreated since the default query requires it
      $properties['dateCreated']['type'] = 'date';

      // Replaces deprecated _timestamp for elasticsearch 2
      if ((int)$this->version >= 2) {
        $properties['lastModified']['type'] = 'date';
      }

      $properties['tags'] = array(
        'type' => $this->textFieldType,
        'analyzer' => 'english',
        'store' => true,
      );
      $data['mappings'][$type]['properties'] = $properties;
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

    $res = $this->check($actual, $this->getIndexConfiguration());
    return $res;
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

  public function didHealthCheck($reachable) {
    static $cache=null;
    if ($cache !== null) {
      return;
    }

    $cache = $reachable;
    $this->ref->didHealthCheck($reachable);
    return $this;
  }

  public function getIndexStats() {
    if ($this->version < 2) {
      return false;
    }
    $uri = '/_stats/';
    $res = $this->executeRequest($uri, array());
    return $res['indices'][$this->index];
  }

  private function executeRequest($path, array $data, $method = 'GET') {
    $uri = $this->ref->getURI($path);
    $data = json_encode($data);
    $future = new HTTPSFuture($uri, $data);
    if ($method != 'GET') {
      $future->setMethod($method);
    }
    if ($this->getTimeout()) {
      $future->setTimeout($this->getTimeout());
    }
    try {
      list($body) = $future->resolvex();
    } catch (HTTPFutureResponseStatus $ex) {
      if ($ex->isTimeout() || (int)$ex->getStatusCode() > 499) {
        $this->didHealthCheck(false);
      }
      throw $ex;
    }

    if ($method != 'GET') {
      return null;
    }

    try {
      $data = phutil_json_decode($body);
      $this->didHealthCheck(true);
      return $data;
    } catch (PhutilJSONParserException $ex) {
      $this->didHealthCheck(false);
      throw new PhutilProxyException(
        pht('ElasticSearch server returned invalid JSON!'),
        $ex);
    }

  }

}
