<?php
namespace nerio93\Facturae;

/**
 * Facturae Item
 *
 * Represents an invoice item
 */
class FacturaeItem {
   /** Subject and exempt operation */
   const SPECIAL_TAXABLE_EVENT_EXEMPT = "01";
   /** Non-subject operation */
   const SPECIAL_TAXABLE_EVENT_NON_SUBJECT = "02";

  private $articleCode = null;
  private $name = null;
  private $description = null;
  private $quantity = 1;
  private $unitOfMeasure = Facturae::UNIT_DEFAULT;
  private $unitPrice = null;
  private $unitPriceWithoutTax = null;
  private $discounts = array();
  private $charges = array();
  private $taxesOutputs = array();
  private $taxesWithheld = array();

  private $issuerContractReference = null;
  private $issuerContractDate = null;
  private $issuerTransactionReference = null;
  private $issuerTransactionDate = null;
  private $receiverContractReference = null;
  private $receiverContractDate = null;
  private $receiverTransactionReference = null;
  private $receiverTransactionDate = null;
  private $fileReference = null;
  private $fileDate = null;
  private $sequenceNumber = null;
  private $periodStart = null;
  private $periodEnd = null;


  /**
   * Construct
   *
   * @param array $properties Item properties as an array
   */
  public function __construct($properties=array()) {
    foreach ($properties as $key=>$value) {
      if ($key == "taxes") continue; // Ignore taxes property, we'll deal with it later
      $this->{$key} = $value;
    }

    // Catalog taxes property (backward compatibility)
    if (isset($properties['taxes'])) {
      foreach ($properties['taxes'] as $r=>$tax) {
        if (empty($r)) continue;
        if (!is_array($tax)) $tax = array("rate"=>$tax, "amount"=>0);
        if (!isset($tax['isWithheld'])) { // Get value by default
          $tax['isWithheld'] = Facturae::isWithheldTax($r);
        }
        if (!isset($tax['surcharge'])) {
          $tax['surcharge'] = 0;
        }
        if ($tax['isWithheld']) {
          $this->taxesWithheld[$r] = $tax;
        } else {
          $this->taxesOutputs[$r] = $tax;
        }
      }
    }

    // Calculate unit and total amount without tax
    if (is_null($this->unitPriceWithoutTax)) {
      // Get taxes effective percent
      $taxesPercent = 1;
      foreach (['taxesOutputs', 'taxesWithheld'] as $i=>$taxesGroupTag) {
        foreach ($this->{$taxesGroupTag} as $taxData) {
          $rate = ($taxData['rate'] + $taxData['surcharge']) / 100;
          if ($i == 1) $rate *= -1; // In case of $taxesWithheld (2nd iteration)
          $taxesPercent += $rate;
        }
      }

      // Adjust discounts and charges according to taxes
      foreach (['discounts', 'charges'] as $groupTag) {
        foreach ($this->{$groupTag} as &$group) {
          if (isset($group['rate'])) continue;
          $hasTaxes = isset($group['hasTaxes']) ? $group['hasTaxes'] : true;
          if ($hasTaxes) $group['amount'] /= $taxesPercent;
        }
      }

      // Apply taxes
      $this->unitPriceWithoutTax = $this->unitPrice / $taxesPercent;
    }
  }


  /**
   * Get data for this item fixing decimals to match invoice settings
   *
   * @param  Facturae $fac Invoice instance
   * @return array         Item data
   */
  public function getData($fac) {
    $addProps = [
      'taxesOutputs' => [],
      'taxesWithheld' => [],
      'discounts' => [],
      'charges' => []
    ];

    $quantity = $this->quantity;
    $unitPriceWithoutTax = $this->unitPriceWithoutTax;
    $totalAmountWithoutTax = $quantity * $unitPriceWithoutTax;

    // NOTE: Special case for Schema v3.2
    // In this schema, an item's total cost (<TotalCost />) has 6 decimals but taxable bases only have 2.
    // We round the first property when using line precision mode to prevent the pair from having different values.
    if ($fac->getSchemaVersion() === Facturae::SCHEMA_3_2) {
      $totalAmountWithoutTax = $fac->pad($totalAmountWithoutTax, 'Tax/TaxableBase', Facturae::PRECISION_LINE);
    }

    // Process charges and discounts
    $grossAmount = $totalAmountWithoutTax;
    foreach (['discounts', 'charges'] as $i=>$groupTag) {
      $factor = ($i == 0) ? -1 : 1;
      foreach ($this->{$groupTag} as $group) {
        if (isset($group['rate'])) {
          /* $rate = $group['rate']; */
          $rate = $fac->pad($group['rate'], 'DiscountCharge/Rate', Facturae::PRECISION_LINE);
          $amount = $totalAmountWithoutTax * ($rate / 100);
        } else {
          $rate = null;
          $amount = $group['amount'];
        }
        $amount = $fac->pad($amount, 'DiscountCharge/Amount', Facturae::PRECISION_LINE);
        $addProps[$groupTag][] = array(
          "reason" => $group['reason'],
          "rate" => $rate,
          "amount" => $amount
        );
        $grossAmount += $amount * $factor;
      }
    }

    // Get taxes
    $totalTaxesOutputs = 0;
    $totalTaxesWithheld = 0;
    foreach (['taxesOutputs', 'taxesWithheld'] as $i=>$taxesGroup) {
      foreach ($this->{$taxesGroup} as $type=>$tax) {
        $taxRate = $fac->pad($tax['rate'], 'Tax/TaxRate', Facturae::PRECISION_LINE);
        $surcharge = $fac->pad($tax['surcharge'], 'Tax/EquivalenceSurcharge', Facturae::PRECISION_LINE);
        $taxableBase = $fac->pad($grossAmount, 'Tax/TaxableBase', Facturae::PRECISION_LINE);
        $taxAmount = $fac->pad($taxableBase*($taxRate/100), 'Tax/TaxAmount', Facturae::PRECISION_LINE);
        $surchargeAmount = $fac->pad($taxableBase*($surcharge/100), 'Tax/EquivalenceSurchargeAmount', Facturae::PRECISION_LINE);
        $addProps[$taxesGroup][$type] = array(
          "base" => $taxableBase,
          "rate" => $taxRate,
          "surcharge" => $surcharge,
          "amount" => $taxAmount,
          "surchargeAmount" => $surchargeAmount
        );
        if ($i == 1) { // In case of $taxesWithheld (2nd iteration)
          $totalTaxesWithheld += $taxAmount + $surchargeAmount;
        } else {
          $totalTaxesOutputs += $taxAmount + $surchargeAmount;
        }
      }
    }

    // Add rest of properties
    $addProps['quantity'] = $fac->pad($quantity, 'Item/Quantity', Facturae::PRECISION_LINE);
    $addProps['unitPriceWithoutTax'] = $fac->pad($unitPriceWithoutTax, 'Item/UnitPriceWithoutTax', Facturae::PRECISION_LINE);
    $addProps['totalAmountWithoutTax'] = $fac->pad($totalAmountWithoutTax, 'Item/TotalCost', Facturae::PRECISION_LINE);
    $addProps['grossAmount'] = $fac->pad($grossAmount, 'Item/GrossAmount', Facturae::PRECISION_LINE);
    $addProps['totalTaxesOutputs'] = $fac->pad($totalTaxesOutputs, 'TotalTaxOutputs', Facturae::PRECISION_LINE);
    $addProps['totalTaxesWithheld'] = $fac->pad($totalTaxesWithheld, 'TotalTaxesWithheld', Facturae::PRECISION_LINE);
    return array_merge(get_object_vars($this), $addProps);
  }

}
