<?php

final class PhabricatorIDsSearchField
  extends PhabricatorSearchField {

  protected function getDefaultValue() {
    return array();
  }

  protected function getValueFromRequest(AphrontRequest $request, $key) {
    return $request->getStrList($key);
  }

  protected function newControl() {
    $viewer = $this->getViewer();
    $expert = (bool) $this->getViewer()
      ->getUserSetting('developer.expert-mode');

    if (strlen($this->getValueForControl())||$expert) {
      return new AphrontFormTextControl();
    } else {
      return null;
    }
  }

  protected function getValueForControl() {
    return implode(', ', parent::getValueForControl());
  }

  protected function newConduitParameterType() {
    return id(new ConduitIntListParameterType())
      ->setAllowEmptyList(false);
  }

}
