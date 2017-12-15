<?php

namespace Drupal\commerce_tax\Plugin\Commerce\TaxType;

use Drupal\commerce_tax\TaxZone;
use Drupal\Core\Form\FormStateInterface;
use Drupal\commerce_tax\TaxNumber;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\profile\Entity\ProfileInterface;
use Drupal\commerce_tax\TaxableType;

/**
 * Provides the Swiss VAT tax type.
 *
 * @CommerceTaxType(
 *   id = "swiss_vat",
 *   label = "Swiss VAT",
 * )
 */
class SwissVat extends LocalTaxTypeBase {

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);
    $form['rates'] = $this->buildRateSummary();
    // Replace the phrase "tax rates" with "VAT rates" to be more precise.
    $form['rates']['#markup'] = $this->t('The following VAT rates are provided:');

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  protected function resolveZones(OrderItemInterface $order_item, ProfileInterface $customer_profile) {
    $resolved_zones = parent::resolveZones($order_item, $customer_profile);

    $customer_address = $customer_profile->address->first();
    $customer_country = $customer_address->getCountryCode();

    if (empty($resolved_zones)) {
      return [];
    }

    $taxable_type = $this->getTaxableType($order_item);
    $is_event = $taxable_type == TaxableType::EVENTS;

    $product = $order_item->getPurchasedEntity()->getProduct();
    $product_type = $this->entityTypeManager->getStorage('commerce_product_type')->load($product->bundle());

    // If a tax registration in CH is available, external events (and other
    // services which are not handled here are) in the EU are handled like
    // EU countries. We will have to exclude swiss taxation in this case.
    // @todo: Reuse the matching EU policy.
    if (!$is_event) {
      $order = $order_item->getOrder();
      $store = $order->getStore();
      $store_address = $store->getAddress();
      $store_country = $store_address->getCountryCode();

      // Products from stores outside switzerland should not be taxed.
      // This is handled by the eu tax rules in this case.
      if ($customer_country != $store_country) {
        // Intra-community supply (B2B) for non-events.
        $resolved_zones = [];
      }
    }
    else {
      // Set zones based on event.
      if ($property_path = $product_type->getThirdPartySetting('commerce_tax', 'event_tax_address_property_path')) {
        $property_path_parts = explode('|', $property_path);

        $event_address = NULL;

        // If field is directly on the product entity.
        if (count($property_path_parts) == 1) {
          $event_address = $product->$property_path_parts[0]->first();
        }
        // If field is on referenced entity we will use our poor-mans
        // property path.
        else {
          $current_element = $product;

          for ($i = 0; $i < count($property_path_parts); $i++) {
            $field_name = $property_path_parts[$i];

            if ($current_element->$field_name && !$current_element->$field_name->isEmpty()) {
              // If its not the last element it is an entity reference.
              if ($i != count($property_path_parts) - 1) {
                $current_element = $current_element->$field_name->entity;
              }
              // Last element is the address field.
              else {
                $event_address = $current_element->$field_name->first();
              }
            }
            else {
              break;
            }
          }
        }

        // If we found an event address use this as the tax origin.
        if ($event_address) {
          $event_country = $event_address->getCountryCode();

          // This is handled by the EuropeanUnionVat, exclude from Swiss Vat.
          // @todo: This should not be added by the EuropeanUnionVat, but this
          // class based on the underlying policy of the EuropeanUnionVat.
          // we need more granular mapping of policies.
          // @todo: $event_country would have to be limited to the EU.
          if ($customer_country != $event_country) {
            $resolved_zones = [];
          }
        }
        else {
          $resolved_zones = [];
        }
      }
      else {
        $resolved_zones = [];
      }
    }

    return $resolved_zones;
  }

  /**
   * {@inheritdoc}
   */
  public function buildZones() {
    $zones = [];
    $zones['ch'] = new TaxZone([
      'id' => 'ch',
      'label' => $this->t('Switzerland'),
      'display_label' => $this->t('VAT'),
      'territories' => [
        ['country_code' => 'CH'],
        ['country_code' => 'LI'],
        // Büsingen.
        ['country_code' => 'DE', 'included_postal_codes' => '78266'],
        // Lake Lugano.
        ['country_code' => 'IT', 'included_postal_codes' => '22060'],
      ],
      'rates' => [
        [
          'id' => 'standard',
          'label' => $this->t('Standard'),
          'percentages' => [
            ['number' => '0.08', 'start_date' => '2011-01-01'],
          ],
          'default' => TRUE,
        ],
        [
          'id' => 'hotel',
          'label' => $this->t('Hotel'),
          'percentages' => [
            ['number' => '0.038', 'start_date' => '2011-01-01'],
          ],
        ],
        [
          'id' => 'reduced',
          'label' => $this->t('Reduced'),
          'percentages' => [
            ['number' => '0.025', 'start_date' => '2011-01-01'],
          ],
        ],
      ],
    ]);

    return $zones;
  }

  /**
   * {@inheritdoc}
   */
  public function isValidTaxNumberFormat(TaxNumber $tax_number, $country_code) {
    // Exception for greece as they don't use their ISO country code.
    $possible_country_codes = $this->getZonesCountryCodes();

    // Only a possibly valid number if we are allowed to check against vies.
    if ($tax_number->isValidFormat() && in_array($country_code, $possible_country_codes)) {

      // The first two chars of eu vat numbers have to be the country code.
      $tax_number_country_code = strtoupper(substr($tax_number->getTaxNumber(), 0, 2));
      if (in_array($tax_number_country_code, $possible_country_codes)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function isValidTaxNumber(TaxNumber $tax_number, $country_code) {
    // Check if tax number has valid format.
    if ($this->isValidTaxNumberFormat($tax_number, $country_code)) {
      return TRUE;
    }

    return FALSE;
  }

}
