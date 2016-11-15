<?php
/**
 * Similar to PhabricatorConfigLocalSource with two differences:
 * 1. this source has a higher priority
 * 2. it reads from environment-specific file: /conf/local/{PHABRICATOR_ENV}.json
 */
final class WmfConfigSource extends PhabricatorConfigSiteSource {

  public function __construct() {
    $config = $this->loadConfig();
    $this->setSource(new PhabricatorConfigDictionarySource($config));
  }

  public function setKeys(array $keys) {
    $result = parent::setKeys($keys);
    $this->saveConfig();
    return $result;
  }

  public function deleteKeys(array $keys) {
    $result = parent::deleteKeys($keys);
    $this->saveConfig();
    return parent::deleteKeys($keys);
  }

  private function loadConfig() {
    $path = $this->getConfigPath();
    if (@file_exists($path)) {
      $data = @file_get_contents($path);
      if ($data) {
        $data = json_decode($data, true);
        if (is_array($data)) {
          return $data;
        }
      }
    }

    return array();
  }

  private function saveConfig() {
    $config = $this->getSource()->getAllKeys();
    $json = new PhutilJSON();
    $data = $json->encodeFormatted($config);
    Filesystem::writeFile($this->getConfigPath(), $data);
  }

  private function getConfigPath() {
    $root = dirname(phutil_get_library_root('phabricator'));
    $env = PhabricatorEnv::getSelectedEnvironmentName();
    $path = "$root/conf/local/$env.json";
    return $path;
  }

}
