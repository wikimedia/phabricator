<?php

final class PhabricatorFulltextResultSet extends Phobject {

  private $phids;
  private $fulltextHighlights;
  private $fulltextTokens;
  private $bodies;

  public function setBodies($bodies) {
    $this->bodies = $bodies;
    return $this;
  }

  public function getBodies() {
    return $this->bodies;
  }

  public function getBodyForPHID($phid) {
    if (!isset($this->bodies[$phid])) {
      return null;
    }
    return $this->bodies[$phid];
  }


  public function setPHIDs($phids) {
    $this->phids = $phids;
    return $this;
  }

  public function getPHIDs() {
    return $this->phids;
  }

  public function setFulltextTokens($fulltext_tokens) {
    $this->fulltextTokens = $fulltext_tokens;
    return $this;
  }

  public function getFulltextTokens() {
    return $this->fulltextTokens;
  }

  public function setFulltextHighlights($highlights) {
    $this->fulltextHighlights = $highlights;
    return $this;
  }

  public function getFulltextHighlights() {
    return $this->fulltextHighlights;
  }

  public function getHighlightsForPHID($phid) {
    if (!isset($this->fulltextHighlights[$phid])) {
      return null;
    }
    return $this->fulltextHighlights[$phid];
  }

}
