<?php

final class WikimediaCommonsRemarkupRule extends PhutilRemarkupRule {

  private $uri;

  public function getPriority() {
    return 350.0;
  }

  public function apply($text) {
    try {
      $this->uri = new PhutilURI($text);
    } catch (Exception $ex) {
      return $text;
    }

    if (preg_match('/^https:\/\/commons\.wikimedia\.org\/(w|wiki)\/'.
                  '(index.php\?title=)?File.*\.(webm|ogv)$/', $text)) {
      return $this->markupCommonsLink();
    }

    return $text;
  }

  public function markupCommonsLink() {
    if ($this->getEngine()->isTextMode() ||
        $this->getEngine()->isHTMLMailMode()) {
      return $this->getEngine()->storeText($this->uri);
    }
    $query_params = $this->uri->getQueryParams();
    $query_params['embedplayer'] = 'yes';
    $commons_src = 'https://commons.wikimedia.org'.$this->uri->getPath().
      '?'.http_build_query($query_params);
    $iframe = $this->newTag(
      'div',
      array(
        'class' => 'embedded-commons-video',
      ),
      $this->newTag(
        'iframe',
        array(
          'width'       => '650',
          'height'      => '400',
          'style'       => 'margin: 1em auto; border: 0px;',
          'src'         => $commons_src,
          'frameborder' => 0,
        ),
        ''));
    return $this->getEngine()->storeText($iframe);
  }

  public function didMarkupText() {
    CelerityAPI::getStaticResourceResponse()
      ->addContentSecurityPolicyURI('frame-src',
         'https://commons.wikimedia.org/');
  }

}
