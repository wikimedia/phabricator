<?php

final class LegalpadDocumentRequireSignatureTransaction
  extends LegalpadDocumentTransactionType {

  const TRANSACTIONTYPE = 'legalpad:require-signature';

  public function generateOldValue($object) {
    return (int)$object->getRequireSignature();
  }

  public function applyInternalEffects($object, $value) {
    $object->setRequireSignature((int)$value);
  }

  public function applyExternalEffects($object, $value) {
    if ($value) {
      $session = new PhabricatorAuthSession();
      queryfx(
        $session->establishConnection('w'),
        'UPDATE %T SET signedLegalpadDocuments = 0',
        $session->getTableName());
    }
  }

  public function shouldHide() {
    return $this->getOldValue()
        == $this->getNewValue();
  }

  public function getTitle() {
    $new = $this->getNewValue();

    if ($new) {
      return pht(
        '%s set the document to require signatures.',
        $this->renderAuthor());
    } else {
      return pht(
        '%s set the document to not require signatures.',
        $this->renderAuthor());
    }
  }

  public function getTitleForFeed() {
    $new = $this->getNewValue();
    if ($new) {
      return pht(
        '%s set the document %s to require signatures.',
        $this->renderAuthor(),
        $this->renderObject());
    } else {
      return pht(
        '%s set the document %s to not require signatures.',
        $this->renderAuthor(),
        $this->renderObject());
    }
  }

  public function validateTransactions($object, array $xactions) {
    $actor = $this->getActor();
    $is_admin = $actor->getIsAdmin();
    $errors = array();

    if (!$xactions) {
      return $errors;
    }

    $old = $this->generateOldValue($object);

    foreach ($xactions as $xaction) {
      $is_changed = (bool)$old != (bool)$xaction->getNewValue();
      if (!$is_admin && $is_changed) {
        $errors[] = $this->newInvalidError(
          pht('Only admins may require signature.'));
      }
    }
    return $errors;
  }

  public function getIcon() {
    return 'fa-pencil-square';
  }

}
