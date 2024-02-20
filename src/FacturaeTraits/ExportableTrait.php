<?php
namespace nerio93\Facturae\FacturaeTraits;

use nerio93\Facturae\Common\XmlTools;

/**
 * Allows a Facturae instance to be exported to XML.
 */
trait ExportableTrait {

  /**
   * Add optional fields
   * @param  object   $item   Subject item
   * @param  string[] $fields Optional fields
   * @return string           Output XML
   */
  private function addOptionalFields($item, $fields) {
    $tools = new XmlTools();

    $res = "";
    foreach ($fields as $key=>$name) {
      if (is_int($key)) $key = $name; // Allow $item to have a different property name
      if (!empty($item[$key])) {
        $xmlTag = ucfirst($name);
        $res .= "<$xmlTag>" . $tools->escape($item[$key]) . "</$xmlTag>";
      }
    }
    return $res;
  }


  /**
   * Export
   * Get Facturae XML data
   * @param  string     $filePath Path to save invoice
   * @return string|int           XML data|Written file bytes
   */
  public function export($filePath=null) {
    $tools = new XmlTools();

    // Notify extensions
    foreach ($this->extensions as $ext) $ext->__onBeforeExport();

    // Prepare document
    $xml = '<fe:Facturae xmlns:ds="http://www.w3.org/2000/09/xmldsig#" ' .
           'xmlns:fe="' . self::$SCHEMA_NS[$this->version] . '">';
    $totals = $this->getTotals();

    $total_taxes = 0;
    $total_before_taxes = 0;
      foreach (["taxesOutputs", "taxesWithheld"] as $taxesGroup) {
          foreach ($this->items as $itemObj) {
              $item = $itemObj->getData($this);
              $total_before_taxes += $item['totalAmountWithoutTax'];
              foreach ($item[$taxesGroup] as $type => $tax) {
                  $total_taxes += $tax['amount'];
              }
          }
      }
      $total_amount = $total_taxes + $totals['grossAmountBeforeTaxes'] ;
      $totals['invoiceAmount'] = $total_amount;
      $paymentDetailsXML = $this->getPaymentDetailsXML($totals,$total_amount);

    $corrective = $this->getCorrective();

    // Add header
    $batchIdentifier = $this->parties['seller']->taxNumber . $this->header['number'] . $this->header['serie'];
    $xml .= '<FileHeader>' .
              '<SchemaVersion>' . $this->version .'</SchemaVersion>' .
              '<Modality>I</Modality>' .
              '<InvoiceIssuerType>EM</InvoiceIssuerType>' .
              '<Batch>' .
                '<BatchIdentifier>' . $batchIdentifier . '</BatchIdentifier>' .
                '<InvoicesCount>1</InvoicesCount>' .
                '<TotalInvoicesAmount>' .
                  '<TotalAmount>' . $this->pad($total_amount, 'InvoiceTotal') . '</TotalAmount>' .
                '</TotalInvoicesAmount>' .
                '<TotalOutstandingAmount>' .
                  '<TotalAmount>' . $this->pad($total_amount, 'InvoiceTotal') . '</TotalAmount>' .
                '</TotalOutstandingAmount>' .
                '<TotalExecutableAmount>' .
                  '<TotalAmount>' . $this->pad($total_amount, 'InvoiceTotal') . '</TotalAmount>' .
                '</TotalExecutableAmount>' .
                '<InvoiceCurrencyCode>' . $this->currency . '</InvoiceCurrencyCode>' .
              '</Batch>';

    // Add factoring assignment data
    if (!is_null($this->parties['assignee'])) {
      $xml .= '<FactoringAssignmentData>';
      $xml .= '<Assignee>' . $this->parties['assignee']->getXML($this->version) . '</Assignee>';
      $xml .= $paymentDetailsXML;
      if (!is_null($this->header['assignmentClauses'])) {
        $xml .= '<FactoringAssignmentClauses>' .
                  $tools->escape($this->header['assignmentClauses']) .
                '</FactoringAssignmentClauses>';
      }
      $xml .= '</FactoringAssignmentData>';
    }

    // Close header
    $xml .= '</FileHeader>';

    // Add parties
    $xml .= '<Parties>' .
              '<SellerParty>' . $this->parties['seller']->getXML($this->version) . '</SellerParty>' .
              '<BuyerParty>' . $this->parties['buyer']->getXML($this->version) . '</BuyerParty>' .
            '</Parties>';

    // Add invoice data
    $xml .= '<Invoices><Invoice>';
    $xml .= '<InvoiceHeader>' .
        '<InvoiceNumber>' . $this->header['number'] . '</InvoiceNumber>' .
        '<InvoiceSeriesCode>' . $this->header['serie'] . '</InvoiceSeriesCode>' .
        '<InvoiceDocumentType>' . $this->documentType . '</InvoiceDocumentType>' .
        '<InvoiceClass>'. $this->invoiceClass .'</InvoiceClass>' ;
     
    // Add invoice data corrective
    if ($corrective !== null) {
      $xml .= '<Corrective>';
      if ($corrective->invoiceNumber !== null) {
        $xml .= '<InvoiceNumber>' . $tools->escape($corrective->invoiceNumber) . '</InvoiceNumber>';
      }
      if ($corrective->invoiceSeriesCode !== null) {
        $xml .= '<InvoiceSeriesCode>' . $tools->escape($corrective->invoiceSeriesCode) . '</InvoiceSeriesCode>';
      }
      $xml .= '<ReasonCode>' . $corrective->reason . '</ReasonCode>';
      $xml .= '<ReasonDescription>' . $tools->escape($corrective->getReasonDescription()) . '</ReasonDescription>';      
      if ($corrective->taxPeriodStart !== null && $corrective->taxPeriodEnd !== null) {
        $start = is_string($corrective->taxPeriodStart) ? strtotime($corrective->taxPeriodStart) : $corrective->taxPeriodStart;
        $end = is_string($corrective->taxPeriodEnd) ? strtotime($corrective->taxPeriodEnd) : $corrective->taxPeriodEnd;
        $xml .= '<TaxPeriod>' .
            '<StartDate>' . date('Y-m-d', $start) . '</StartDate>' .
            '<EndDate>' . date('Y-m-d', $end) . '</EndDate>' .
          '</TaxPeriod>';
      }
      $xml .= '<CorrectionMethod>' . $corrective->correctionMethod . '</CorrectionMethod>';      
      $xml .= '<CorrectionMethodDescription>' .
      $tools->escape($corrective->getCorrectionMethodDescription()) .
        '</CorrectionMethodDescription>';
      if($corrective->additionalReasonDescription!==null){
        $xml .= '<AdditionalReasonDescription>' . $corrective->additionalReasonDescription . '</AdditionalReasonDescription>';
      }
      if ($corrective->invoiceIssueDate !== null) {
        $invoiceIssueDate = is_string($corrective->invoiceIssueDate) ? strtotime($corrective->invoiceIssueDate) : $corrective->invoiceIssueDate;
        $xml .= '<InvoiceIssueDate>' . date('Y-m-d', $invoiceIssueDate) . '</InvoiceIssueDate>';
      }
      $xml .= '</Corrective>';
    }

    $xml .= '</InvoiceHeader>';
    $xml .= '<InvoiceIssueData>';
    $xml .= '<IssueDate>' . date('Y-m-d', $this->header['issueDate']) . '</IssueDate>';
    if (!is_null($this->header['startDate'])) {
      $xml .= '<InvoicingPeriod>' .
          '<StartDate>' . date('Y-m-d', $this->header['startDate']) . '</StartDate>' .
          '<EndDate>' . date('Y-m-d', $this->header['endDate']) . '</EndDate>' .
        '</InvoicingPeriod>';
    }
    $xml .= '<InvoiceCurrencyCode>' . $this->currency . '</InvoiceCurrencyCode>';
    $xml .= '<TaxCurrencyCode>' . $this->currency . '</TaxCurrencyCode>';
    $xml .= '<LanguageName>' . $this->language . '</LanguageName>';
    $xml .= $this->addOptionalFields($this->header, [
      "description" => "InvoiceDescription",
      "receiverTransactionReference",
      "fileReference",
      "receiverContractReference"
    ]);
    $xml .= '</InvoiceIssueData>';

    // Add invoice taxes

    foreach (["taxesOutputs", "taxesWithheld"] as $taxesGroup) {
      if (count($totals[$taxesGroup]) == 0) continue;
      $xmlTag = ucfirst($taxesGroup); // Just capitalize variable name
      $xml .= "<$xmlTag>";
      /*
      foreach ($totals[$taxesGroup] as $type=>$taxRows) {
        foreach ($taxRows as $tax) {
          $xml .= '<Tax>' .
                    '<TaxTypeCode>' . $type . '</TaxTypeCode>' .
                    '<TaxRate>' . $this->pad($tax['rate'], 'Tax/TaxRate') . '</TaxRate>' .
                    '<TaxableBase>' .
                      '<TotalAmount>' . $this->pad($tax['base'], 'Tax/TaxableBase') . '</TotalAmount>' .
                    '</TaxableBase>' .
                    '<TaxAmount>' .
                      '<TotalAmount>' . $this->pad($tax['amount'], 'Tax/TaxAmount') . '</TotalAmount>' .
                    '</TaxAmount>';
          if ($tax['surcharge'] != 0) {
            $xml .= '<EquivalenceSurcharge>' . $this->pad($tax['surcharge'], 'Tax/EquivalenceSurcharge') . '</EquivalenceSurcharge>' .
                    '<EquivalenceSurchargeAmount>' .
                      '<TotalAmount>' . $this->pad($tax['surchargeAmount'], 'Tax/EquivalenceSurchargeAmount') . '</TotalAmount>' .
                    '</EquivalenceSurchargeAmount>';
          }
          $xml .= '</Tax>';
        }
      }*/

        foreach ($this->items as $itemObj) {
            $item = $itemObj->getData($this);
            foreach ($item[$taxesGroup] as $type => $tax) {
              //  $total_taxes += $tax['amount'];
                $xml .= '<Tax>' .
                    '<TaxTypeCode>' . $type . '</TaxTypeCode>' .
                    '<TaxRate>' . $this->pad($tax['rate'], 'Tax/TaxRate') . '</TaxRate>' .
                    '<TaxableBase>' .
                    '<TotalAmount>' . $this->pad($tax['base'], 'Tax/TaxableBase') . '</TotalAmount>' .
                    '</TaxableBase>' .
                    '<TaxAmount>' .
                    '<TotalAmount>' . $this->pad($tax['amount'], 'Tax/TaxAmount') . '</TotalAmount>' .
                    '</TaxAmount>';
                $xml .= '</Tax>';
            }
        }
      $xml .= "</$xmlTag>";
    }

    // Add invoice totals
    $xml .= '<InvoiceTotals>';
    $xml .= '<TotalGrossAmount>' . $this->pad($totals['grossAmount'], 'TotalGrossAmount') . '</TotalGrossAmount>';

    // Add general discounts and charges
    $generalGroups = array(
      ['GeneralDiscounts', 'Discount'],
      ['GeneralSurcharges', 'Charge']
    );
    foreach (['generalDiscounts', 'generalCharges'] as $g=>$groupTag) {
      if (empty($totals[$groupTag])) continue;
      $xmlTag = $generalGroups[$g][1];
      $xml .= '<' . $generalGroups[$g][0] . '>';
      foreach ($totals[$groupTag] as $elem) {
        $xml .= "<$xmlTag>";
        $xml .= "<{$xmlTag}Reason>" . $tools->escape($elem['reason']) . "</{$xmlTag}Reason>";
        if (!is_null($elem['rate'])) {
          $xml .= "<{$xmlTag}Rate>" . $this->pad($elem['rate'], 'DiscountCharge/Rate') . "</{$xmlTag}Rate>";
        }
        $xml .="<{$xmlTag}Amount>" . $this->pad($elem['amount'], 'DiscountCharge/Amount') . "</{$xmlTag}Amount>";
        $xml .= "</$xmlTag>";
      }
      $xml .= '</' . $generalGroups[$g][0] . '>';
    }

    $invoice_total = $totals['grossAmountBeforeTaxes'] + $total_taxes;

    $xml .= '<TotalGeneralDiscounts>' . $this->pad($totals['totalGeneralDiscounts'], 'TotalGeneralDiscounts') . '</TotalGeneralDiscounts>';
    $xml .= '<TotalGeneralSurcharges>' . $this->pad($totals['totalGeneralCharges'], 'TotalGeneralSurcharges') . '</TotalGeneralSurcharges>';
    $xml .= '<TotalGrossAmountBeforeTaxes>' . $this->pad($totals['grossAmountBeforeTaxes'], 'TotalGrossAmountBeforeTaxes') . '</TotalGrossAmountBeforeTaxes>';
    $xml .= '<TotalTaxOutputs>' . $this->pad($total_taxes, 'TotalTaxOutputs') . '</TotalTaxOutputs>';
    $xml .= '<TotalTaxesWithheld>' . $this->pad($totals['totalTaxesWithheld'], 'TotalTaxesWithheld') . '</TotalTaxesWithheld>';
    $xml .= '<InvoiceTotal>' . $this->pad($invoice_total, 'InvoiceTotal') . '</InvoiceTotal>';
    $xml .= '<TotalOutstandingAmount>' . $this->pad($invoice_total, 'InvoiceTotal') . '</TotalOutstandingAmount>';
    $xml .= '<TotalExecutableAmount>' . $this->pad($invoice_total, 'InvoiceTotal') . '</TotalExecutableAmount>';
    $xml .= '</InvoiceTotals>';

    // Add invoice items
    $xml .= '<Items>';
    foreach ($this->items as $itemObj) {
      $item = $itemObj->getData($this);
      $xml .= '<InvoiceLine>';

      // Add optional fields
      $xml .= $this->addOptionalFields($item, [
        "issuerContractReference", "issuerContractDate",
        "issuerTransactionReference", "issuerTransactionDate",
        "receiverContractReference", "receiverContractDate",
        "receiverTransactionReference", "receiverTransactionDate",
        "fileReference", "fileDate", "sequenceNumber"
      ]);

      // Add required fields
      $xml .= '<ItemDescription>' . $tools->escape($item['name']) . '</ItemDescription>' .
        '<Quantity>' . $this->pad($item['quantity'], 'Item/Quantity') . '</Quantity>' .
        '<UnitOfMeasure>' . $item['unitOfMeasure'] . '</UnitOfMeasure>' .
        '<UnitPriceWithoutTax>' . $this->pad($item['unitPriceWithoutTax'], 'Item/UnitPriceWithoutTax') . '</UnitPriceWithoutTax>' .
        '<TotalCost>' . $this->pad($item['totalAmountWithoutTax'], 'Item/TotalCost') . '</TotalCost>';

      // Add discounts and charges
      $itemGroups = array(
        ['DiscountsAndRebates', 'Discount'],
        ['Charges', 'Charge']
      );
      foreach (['discounts', 'charges'] as $g=>$group) {
        if (empty($item[$group])) continue;
        $groupTag = $itemGroups[$g][1];
        $xml .= '<' . $itemGroups[$g][0] . '>';
        foreach ($item[$group] as $elem) {
          $xml .= "<$groupTag>";
          $xml .= "<{$groupTag}Reason>" . $tools->escape($elem['reason']) . "</{$groupTag}Reason>";
          if (!is_null($elem['rate'])) {
            $xml .= "<{$groupTag}Rate>" . $this->pad($elem['rate'], 'DiscountCharge/Rate') . "</{$groupTag}Rate>";
          }
          $xml .="<{$groupTag}Amount>" . $this->pad($elem['amount'], 'DiscountCharge/Amount') . "</{$groupTag}Amount>";
          $xml .= "</$groupTag>";
        }
        $xml .= '</' . $itemGroups[$g][0] . '>';
      }

      // Add gross amount
      $xml .= '<GrossAmount>' . $this->pad($item['grossAmount'], 'Item/GrossAmount') . '</GrossAmount>';

      // Add item taxes
      // NOTE: As you can see here, taxesWithheld is before taxesOutputs.
      // This is intentional, as most official administrations would mark the
      // invoice as invalid XML if the order is incorrect.
      foreach (["taxesWithheld", "taxesOutputs"] as $taxesGroup) {
        if (count($item[$taxesGroup]) == 0) continue;
        $xmlTag = ucfirst($taxesGroup); // Just capitalize variable name
        $xml .= "<$xmlTag>";
        foreach ($item[$taxesGroup] as $type=>$tax) {
          $xml .= '<Tax>' .
                    '<TaxTypeCode>' . $type . '</TaxTypeCode>' .
                    '<TaxRate>' . $this->pad($tax['rate'], 'Tax/TaxRate') . '</TaxRate>' .
                    '<TaxableBase>' .
                      '<TotalAmount>' . $this->pad($tax['base'], 'Tax/TaxableBase') . '</TotalAmount>' .
                    '</TaxableBase>' .
                    '<TaxAmount>' .
                      '<TotalAmount>' . $this->pad($tax['amount'], 'Tax/TaxAmount') . '</TotalAmount>' .
                    '</TaxAmount>';
          if ($tax['surcharge'] != 0) {
            $xml .= '<EquivalenceSurcharge>' . $this->pad($tax['surcharge'], 'Tax/EquivalenceSurcharge') . '</EquivalenceSurcharge>' .
                    '<EquivalenceSurchargeAmount>' .
                      '<TotalAmount>' .
                        $this->pad($tax['surchargeAmount'], 'Tax/EquivalenceSurchargeAmount') .
                      '</TotalAmount>' .
                    '</EquivalenceSurchargeAmount>';
          }
          $xml .= '</Tax>';
        }
        $xml .= "</$xmlTag>";
      }

      // Add line period dates
      if (!empty($item['periodStart']) && !empty($item['periodEnd'])) {
        $xml .= '<LineItemPeriod>';
        $xml .= '<StartDate>' . $tools->escape($item['periodStart']) . '</StartDate>';
        $xml .= '<EndDate>' . $tools->escape($item['periodEnd']) . '</EndDate>';
        $xml .= '</LineItemPeriod>';
      }

      // Add more optional fields
      $xml .= $this->addOptionalFields($item, [
        "description" => "AdditionalLineItemInformation",
        "articleCode"
      ]);

      // Close invoice line
      $xml .= '</InvoiceLine>';
    }
    $xml .= '</Items>';

    // Add payment details
    $xml .= $paymentDetailsXML;

    // Add legal literals
    if (count($this->legalLiterals) > 0) {
      $xml .= '<LegalLiterals>';
      foreach ($this->legalLiterals as $reference) {
        $xml .= '<LegalReference>' . $tools->escape($reference) . '</LegalReference>';
      }
      $xml .= '</LegalLiterals>';
    }

    // Add additional data
    $xml .= $this->getAdditionalDataXML();

    // Close invoice and document
    $xml .= '</Invoice></Invoices></fe:Facturae>';
    foreach ($this->extensions as $ext) $xml = $ext->__onBeforeSign($xml);

    // Add signature
    $xml = $this->injectSignature($xml);
    foreach ($this->extensions as $ext) $xml = $ext->__onAfterSign($xml);

    // Prepend content type
    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . $xml;

    // Save document
    if (!is_null($filePath)) return file_put_contents($filePath, $xml);
    return $xml;
  }


  /**
   * Get payment details XML
   * @param  array  $totals Invoice totals
   * @return string         Payment details XML, empty string if not available
   */
  private function getPaymentDetailsXML($totals,$total_amount = -1) {
    if (is_null($this->header['paymentMethod'])) return "";

    $dueDate = is_null($this->header['dueDate']) ? $this->header['issueDate'] : $this->header['dueDate'];
    $xml  = '<PaymentDetails>';
    $xml .= '<Installment>';
    $xml .= '<InstallmentDueDate>' . date('Y-m-d', $dueDate) . '</InstallmentDueDate>';
    $xml .= '<InstallmentAmount>' . $this->pad($total_amount!=-1?$total_amount:$totals['invoiceAmount'], 'InvoiceTotal') . '</InstallmentAmount>';
    $xml .= '<PaymentMeans>' . $this->header['paymentMethod'] . '</PaymentMeans>';
    if (!is_null($this->header['paymentIBAN'])) {
      $accountType = ($this->header['paymentMethod'] == self::PAYMENT_DEBIT) ? "AccountToBeDebited" : "AccountToBeCredited";
      $xml .= "<$accountType>";
      $xml .= '<IBAN>' . $this->header['paymentIBAN'] . '</IBAN>';
      if (!is_null($this->header['paymentBIC'])) {
        $xml .= '<BIC>' . $this->header['paymentBIC'] . '</BIC>';
      }

      /*
      if($this->header['bankCode'] !== '')
        $xml .= '<BankCode>' . $this->header['bankCode'] . '</BankCode>';
      if($this->header['branch'] !== '')
        $xml .= '<BranchCode>' . $this->header['branch'] . '</BranchCode>';

      if($this->header['countryCode'] !== '' && $this->header['countryCode'] === 'ESP'){
          $xml .= '<BranchInSpainAddress>';
          if($this->header['address'] !== '')
            $xml .= '<Address>' . $this->header['address'] . '</Address>';
          if($this->header['postalCode'] !== '')
            $xml .= '<PostCode>' . $this->header['postalCode'] . '</PostCode>';
          if($this->header['town'] !== '')
            $xml .= '<Town>' . $this->header['town'] . '</Town>';
          if($this->header['province'] !== '')
            $xml .= '<Province>' . $this->header['province'] . '</Province>';
          $xml .= '<CountryCode>' . $this->header['countryCode'] . '</CountryCode>';
          $xml .= '</BranchInSpainAddress>';
      }elseif($this->header['countryCode'] !== ''){
          $xml .= '<OverseasBranchAddress>';
          if($this->header['address'] !== '')
            $xml .= '<Address>' . $this->header['address'] . '</Address>';
          if($this->header['postalCode'] !== '' || $this->header['town'])
            $xml .= '<PostCodeAndTown>' . $this->header['town'] . ' ' . $this->header['postalCode']  . '</PostCodeAndTown>';
          if($this->header['province'] !== '')
            $xml .= '<Province>' . $this->header['province'] . '</Province>';
          $xml .= '<CountryCode>' . $this->header['countryCode'] . '</CountryCode>';
          $xml .= '</OverseasBranchAddress>';
      }*/
      $xml .= "</$accountType>";
    }
    $xml .= '</Installment>';
    $xml .= '</PaymentDetails>';

    return $xml;
  }


  /**
   * Get additional data XML
   * @return string Additional data XML
   */
  private function getAdditionalDataXML() {
    $extensionsXML = array();
    foreach ($this->extensions as $ext) {
      $extXML = $ext->__getAdditionalData();
      if (!empty($extXML)) $extensionsXML[] = $extXML;
    }
    $relInvoice =& $this->header['relatedInvoice'];
    $additionalInfo =& $this->header['additionalInformation'];

    // Validate additional data fields
    $hasData = !empty($extensionsXML) || !empty($this->attachments) || !empty($relInvoice) || !empty($additionalInfo);
    if (!$hasData) return "";

    // Generate initial XML block
    $tools = new XmlTools();
    $xml = '<AdditionalData>';
    if (!empty($relInvoice)) $xml .= '<RelatedInvoice>' . $tools->escape($relInvoice) . '</RelatedInvoice>';

    // Add attachments
    if (!empty($this->attachments)) {
      $xml .= '<RelatedDocuments>';
      foreach ($this->attachments as $att) {
        $type = explode('/', $att['file']->getMimeType());
        $type = end($type);
        $xml .= '<Attachment>';
        $xml .= '<AttachmentCompressionAlgorithm>NONE</AttachmentCompressionAlgorithm>';
        $xml .= '<AttachmentFormat>' . $tools->escape($type) . '</AttachmentFormat>';
        $xml .= '<AttachmentEncoding>BASE64</AttachmentEncoding>';
        $xml .= '<AttachmentDescription>' . $tools->escape($att['description']) . '</AttachmentDescription>';
        $xml .= '<AttachmentData>' . base64_encode($att['file']->getData()) . '</AttachmentData>';
        $xml .= '</Attachment>';
      }
      $xml .= '</RelatedDocuments>';
    }

    // Add additional information
    if (!empty($additionalInfo)) {
      $xml .= '<InvoiceAdditionalInformation>' . $tools->escape($additionalInfo) . '</InvoiceAdditionalInformation>';
    }

    // Add extensions data
    if (!empty($extensionsXML)) $xml .= '<Extensions>' . implode('', $extensionsXML) . '</Extensions>';

    $xml .= '</AdditionalData>';
    return $xml;
  }

}
