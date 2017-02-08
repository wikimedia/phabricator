<?php
/**
 * PhabricatorFulltextStorageEngineAggregate is a simple wrapper for multiple
 * Fulltext Storage Engine instances. You can call any method that is supported
 * by PhabricatorFulltextStorageEngine and the call will be forwarded to each
 * of the wrapped instances and receive an array of the values returned.
 */
class PhabricatorFulltextStorageEngineAggregate {
  protected $engines = array();
  protected $need_readable = true;
  protected $need_writable = false;

  public function addEngines($engines) {
    if (!is_array($engines)) {
      $engines = array($engines);
    }

    foreach ($engines as $engine) {
      $this->engines[] = $engine;
    }
    return $this;
  }

  /**
   * filter all calls to skip aggregated engines that are not writable
   * @arg bool $need_writable true to skip read-only engines
   */
  public function needWritable($need_writable) {
    $this->needWritable = $need_writable;
    return $this;
  }

  /**
   * filter all calls to skip aggregated engines that are not readable
   * @arg bool $need_writable true to skip disabled engines
   */
  public function needReadable($need_readable) {
    $this->needReadable = $need_readable;
    return $this;
  }

  public function executeSearch($query) {
    $all_results = $this->__call('executeSearch', array($query));
    $combined = array();
    foreach ($all_results as $one_engine) {
      $combined += $one_engine;
    }
    return array_unique($combined);
  }

  /**
   * Intercept all method calls to this wrapper and redirect to a corresponding
   * method on wrapped instances (that match the filters currently in effect.)
   */
  public function __call($name, $args) {
    $results = array();
    foreach ($this->engines as $engine) {
      if ($this->need_readable && !$engine->isEnabled()) {
        continue;
      }
      if ($this->need_writable && !$engine->isWritable()) {
        continue;
      }
      $results[] = call_user_func_array(array($engine, $name), $args);
    }
    return $results;
  }

}
