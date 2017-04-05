<?php
class PhabricatorFulltextResult
  implements PhabricatorPolicyInterface {

  protected $handle;
  protected $fields = array();
  protected $phid;
  protected $type;

  public function __construct($phid) {
    $this->setPHID($phid);
  }

  public function setPHID($phid) {
    $this->phid = $phid;
    $this->type = phid_get_type($phid);
    return $this;
  }

  public function getPHID() {
    return $this->phid;
  }

  public function getType() {
    return $this->type;
  }

  /**
   * @return PhabricatorObjectHandle
   */
  public function getHandle() {
    return $this->handle;
  }

  public function setHandle($handle) {
    $this->handle = $handle;
    return $this;
  }

  public function setHighlights($highlights) {
    if (!$highlights) {
      return;
    }

    foreach ($highlights as $field => $values) {
      $this->fields[$field] = join(' ... ', array_slice($values,0,2));
    }
  }

  public function getHighlights($field='all') {
    if ($field == 'all') {
      return phutil_safe_html(join('<br/>', $this->fields));
    } else if (!isset($this->fields[$field])) {
      return '';
    }
    return phutil_safe_html($this->fields[$field]);
  }

/* -(  PhabricatorPolicyInterface  )----------------------------------------- */

  public function getCapabilities() {
    return $this->handle->getCapabilities();
  }

  public function getPolicy($capability) {
    return $this->handle->getPolicy($capability);
  }

  public function hasAutomaticCapability($capability, PhabricatorUser $viewer) {
    return $this->handle->hasAutomaticCapability($capability, $viewer);
  }

}
