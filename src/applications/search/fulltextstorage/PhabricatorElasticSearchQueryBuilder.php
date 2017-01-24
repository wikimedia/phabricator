<?php

class PhabricatorElasticSearchQueryBuilder {
  protected $name;
  protected $terms;
  protected $current_context = null;
  protected $parent = null;

  function __construct($name='query') {
    $this->name = $name;
    $this->terms = array();
  }

  function toArray() {
    return array($this->name => $this->getTerms());
  }

  function getTerms() {
    return $this->terms;
  }

  function branch($term) {
    $child = new PhabricatorElasticSearchQueryBuilder($term);
    $child->parent = $this;
    $this->terms[$this->current_context][] = $child;
    return $child;
  }

  function bool() {
    return $this->branch('bool');
  }

  function match() {
    return $this->branch('match');
  }

  function query_string($str, $fields=['title','body']) {

  }

  function __call($name, $args) {
    $this->current_context = $name;

    foreach($args as $arg) {
      if (empty($arg)){
        continue;
      }
      $this->terms[$name][] = $arg;
    }
    return $this;
  }

  function __invoke() {
    return $this->toArray();
  }

  function __toString() {
    return json_encode($this->toArray());
  }

}
