<?php

final class PhabricatorPHIDsSearchField
  extends PhabricatorSearchField {

  protected function getDefaultValue() {
    return array();
  }

  protected function getValueFromRequest(AphrontRequest $request, $key) {
    return $request->getStrList($key);
  }

  protected function newControl() {

    $viewer = $this->getViewer();
    $expert = boolval();
    if (strlen($this->getValueForControl())
      || $this->getViewer()->getUserSetting('developer.expert-mode')) {
      return new AphrontFormTextControl();
    } else {
      return null;
    }
  }

  protected function getValueForControl() {
    return implode(', ', parent::getValueForControl());
  }

  protected function newConduitParameterType() {
    return id(new ConduitPHIDListParameterType())
      ->setAllowEmptyList(false);
  }

}
