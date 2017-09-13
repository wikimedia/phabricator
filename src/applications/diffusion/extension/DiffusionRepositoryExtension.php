<?php

abstract class DiffusionRepositoryExtension {
  abstract public function willHandleRequest(
    AphrontRequest $request, PhabricatorRepository $repository);

  abstract public function willModifyPageView(
    PhabricatorUser $viewer,
    AphrontRequest $request,
    PhabricatorRepository $repository,
    DiffusionRequest $drequest);

    public static function loadRepositoryExtensions($request, $repository) {
      $extensions = id(new PhutilClassMapQuery())
        ->setAncestorClass('DiffusionRepositoryExtension')
        ->execute();

      foreach ($extensions as $id => $extension) {
        $response = $extension->willHandleRequest($request, $repository);
        if ($response instanceof AphrontResponse) {
          throw new DiffusionOverrideResponseException($response);
        }
        if ($response === false) {
          unset($extensions[$id]);
        }
      }
      return $extensions;
    }

}
