<?php
namespace nerio93\Facturae\Face;

use nerio93\Facturae\Face\Traits\Faceb2bTrait;
use nerio93\Facturae\Face\Traits\StageableTrait;

class Faceb2bClient extends SoapClient {
  use StageableTrait;
  use Faceb2bTrait;

  /**
   * Get endpoint URL
   * @return string Endpoint URL
   */
  protected function getEndpointUrl() {
    return $this->isProduction() ?
      "https://ws.faceb2b.gob.es/sv1/invoice" :
      "https://se-ws-faceb2b.redsara.es/sv1/invoice";
  }
}
