<?php

abstract class DiffusionRepositoryExtension {
  abstract public function willHandleRequest(
    AphrontRequest $request, PhabricatorRepository $repository);

  abstract public function willModifyPageView(
    PhabricatorUser $viewer,
    AphrontRequest $request,
    PhabricatorRepository $repository,
    DiffusionRequest $drequest);

}
