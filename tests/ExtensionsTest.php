<?php
namespace nerio93\Facturae\Tests;

use nerio93\Facturae\Facturae;
use nerio93\Facturae\FacturaeCentre;
use nerio93\Facturae\Tests\Extensions\DisclaimerExtension;

final class ExtensionsTest extends AbstractTest {

  const FILE_PATH = self::OUTPUT_DIR . "/salida-extensiones.xsig";
  const FB2B_XSD_PATH = "https://administracionelectronica.gob.es/ctt/resources/Soluciones/2811/Descargas/Extension%20FACEB2B%20v1-1.xsd";

  /**
   * Test extensions
   */
  public function testExtensions() {
    // Creamos una factura estándar
    $fac = $this->getBaseInvoice();
    $fac->addItem("Línea de producto", 100, 1, Facturae::TAX_IVA, 10);

    // Obtener la extensión de FACeB2B y establecemos la entidad pública
    $b2b = $fac->getExtension('Fb2b');
    $b2b->setPublicOrganismCode('E00003301');
    $b2b->setContractReference('333000');

    // Añadimos los remitentes (vendedores) de FACeB2B
    $b2b->addCentre(new FacturaeCentre([
      "code" => "ES12345678Z0002",
      "name" => "Unidad DIRe Vendedora 0002",
      "role" => FacturaeCentre::ROLE_B2B_SELLER
    ]), false);
    $b2b->addCentre(new FacturaeCentre([
      "code" => "ES12345678Z0003",
      "name" => "Unidad DIRe Fiscal 0003",
      "role" => FacturaeCentre::ROLE_B2B_FISCAL
    ]), false);

    // Añadimos los destinatarios (compradores) de FACeB2B
    $b2b->setReceiver(new FacturaeCentre([
      "code" => "51558103JES0001",
      "name" => "Centro administrativo receptor"
    ]));
    $b2b->addCentre(new FacturaeCentre([
      "code" => "ESB123456740002",
      "name" => "Unidad DIRe Compradora 0002",
      "role" => FacturaeCentre::ROLE_B2B_BUYER
    ]));
    $b2b->addCentre(new FacturaeCentre([
      "code" => "ESB123456740003",
      "name" => "Unidad DIRe Fiscal 0003",
      "role" => FacturaeCentre::ROLE_B2B_FISCAL
    ]));
    $b2b->addCentre(new FacturaeCentre([
      "code" => "ESB123456740004",
      "role" => FacturaeCentre::ROLE_B2B_COLLECTOR
    ]));

    // Añadimos la extensión externa de prueba
    $disclaimer = $fac->getExtension(DisclaimerExtension::class);
    $disclaimer->enable();

    // Exportamos la factura
    $fac->sign(self::CERTS_DIR . "/facturae.p12", null, self::FACTURAE_CERT_PASS);
    $success = ($fac->export(self::FILE_PATH) !== false);
    $this->assertTrue($success);

    $rawXml = file_get_contents(self::FILE_PATH);
    $extXml = explode('<Extensions>', $rawXml);
    $extXml = explode('</Extensions>', $extXml[1])[0];

    // Validamos la parte de FACeB2B
    $schemaPath = $this->getSchema();
    $faceXml = new \DOMDocument();
    $faceXml->loadXML($extXml);
    $isValidXml = $faceXml->schemaValidate($schemaPath);
    $this->assertTrue($isValidXml);
    unlink($schemaPath);

    // Validamos la ejecución de DisclaimerExtension
    $disclaimerPos = strpos($rawXml, '<LegalReference>' . $disclaimer->getDisclaimer() . '</LegalReference>');
    $this->assertTrue($disclaimerPos !== false);
  }

   /**
   * Get path to FaceB2B schema file
   * @return string Path to schema file
   */
  private function getSchema() {
    // Get XSD contents
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, self::FB2B_XSD_PATH);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_COOKIEFILE, '');
    $res = curl_exec($ch);
    curl_close($ch);
    unset($ch);

    // Save to disk
    $path = self::OUTPUT_DIR . "/faceb2b.xsd";
    file_put_contents($path, $res);

    return $path;
  }
}
