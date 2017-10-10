<?php

final class PhabricatorEditEngineConfigurationSaveController
  extends PhabricatorEditEngineController {

  public function handleRequest(AphrontRequest $request) {
    $engine_key = $request->getURIData('engineKey');
    $this->setEngineKey($engine_key);

    $key = $request->getURIData('key');
    $viewer = $this->getViewer();

    $config = id(new PhabricatorEditEngineConfigurationQuery())
      ->setViewer($viewer)
      ->withEngineKeys(array($engine_key))
      ->withIdentifiers(array($key))
      ->executeOne();
    if (!$config) {
      return id(new Aphront404Response());
    }

    $view_uri = $config->getURI();

    if ($config->getID() && !$request->isFormPost()) {
      return $this->newDialog()
        ->setTitle(pht('Duplicate Form'))
        ->appendParagraph(
          pht('Create another form with the same settings as this one?'))
        ->addSubmitButton(pht('Duplicate'))
        ->addHiddenInput('action', 'duplicate')
        ->addCancelButton($view_uri);
    }

    if ($request->isFormPost()) {
      $editor = id(new PhabricatorEditEngineConfigurationEditor())
        ->setActor($viewer)
        ->setContentSourceFromRequest($request)
        ->setContinueOnNoEffect(true)
        ->setContinueOnMissingFields(true);

      if ($request->getStr('action') == 'duplicate') {
        $engine = $config->getEngine();
        $new_config = PhabricatorEditEngineConfiguration
          ::initializeNewConfiguration($viewer, $engine);
        $new_config->setName($config->getDisplayName());
        $new_config->setPreamble($config->getPreamble());
        $new_config->setFieldOrder($config->getFieldOrder());
        $new_config->setFieldLocks($config->getFieldLocks());
        $new_config->setProperty('defaults',
          $config->getProperty('defaults', array()));
        $new_config->setIsEdit($config->getIsEdit());
        $config = $new_config;
      }
      $editor->applyTransactions($config, array());
      return id(new AphrontRedirectResponse())
        ->setURI($config->getURI());
    }

    // TODO: Explain what this means in more detail once the implications are
    // more clear, or just link to some docs or something.

    return $this->newDialog()
      ->setTitle(pht('Make Builtin Editable'))
      ->appendParagraph(
        pht('Make this builtin form editable?'))
      ->addSubmitButton(pht('Make Editable'))
      ->addCancelButton($view_uri);
  }

}
