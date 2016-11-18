<?php
/**
 * Similar to PhabricatorConfigLocalSource with two differences:
 * 1. this source has a higher priority
 * 2. it reads from environment-specific file: /conf/local/{PHABRICATOR_ENV}.json
 */
final class WmfConfigSource extends PhabricatorConfigSiteSource {

  private $root = null;
  private $readableConfigFiles = null;

  public function __construct() {
    $this->root = dirname(phutil_get_library_root('phabricator'));
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

  /**
  * get a list of config files which are owned by
  * the same gid as the current process.
  */
  private function getReadableConfigFiles() {
    if ($this->readableConfigFiles != null) {
      return $this->readableConfigFiles;
    }
    $userinfo = posix_getpwuid(posix_geteuid());
    $gid = $userinfo['gid'];
    $results = array();
    $files = glob("$this->root/conf/local/*.json");
    foreach ($files as $filename) {
      if (substr($filename, -10) !== 'local.json' &&
          filegroup($filename) == $gid) {
        $results[] = $filename;
      }
    }
    return $this->readableConfigFiles = $results;
  }

  /** get the path to a config file for the selected environment,
   * falling back to any readable config file or the alternate
   * environment specified by $default
   */
  private function getReadablePathForEnv($env, $default = false) {
    $path = "$this->root/conf/local/$env.json";
    if (strlen($env) && file_exists($path) && is_readable($path)) {
      return $path;
    }

    $files = $this->getReadableConfigFiles();
    if (count($files)) {
      return array_pop($files);
    }

    if ($default) {
      $path = "$this->root/conf/local/$default.json";
      if (file_exists($path)) {
        return $path;
      }
    }
    return false;
  }

  /** get the config file for the current environment, if no environment is set,
   * return any readable config file. If all else fails, just return 'phd.json'
   */
  private function getConfigPath() {
    $environment = PhabricatorEnv::getSelectedEnvironmentName();
    if (isset($_ENV['PHABRICATOR_INSTANCE'])) {
      $default = $environment;
      $environment = $_ENV['PHABRICATOR_INSTANCE'];
    } else {
      $default = 'phd';
    }

    $path = $this->getReadablePathForEnv($environment, $default);

    $_ENV['PHABRICATOR_INSTANCE'] = $environment;
    putenv("PHABRICATOR_INSTANCE=$environment");

    return $path;
  }

}
