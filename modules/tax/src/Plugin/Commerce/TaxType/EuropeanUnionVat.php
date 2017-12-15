<?php

namespace Drupal\commerce_tax\Plugin\Commerce\TaxType;

use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_tax\TaxableType;
use Drupal\commerce_tax\TaxZone;
use Drupal\commerce_tax\TaxNumber;
use Drupal\Core\Form\FormStateInterface;
use Drupal\profile\Entity\ProfileInterface;

/**
 * Provides the European Union VAT tax type.
 *
 * @CommerceTaxType(
 *   id = "european_union_vat",
 *   label = "European Union VAT",
 * )
 */
class EuropeanUnionVat extends LocalTaxTypeBase {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
        'validate_vies' => FALSE,
      ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);
    $form['rates'] = $this->buildRateSummary();
    // Replace the phrase "tax rates" with "VAT rates" to be more precise.
    $form['rates']['#markup'] = $this->t('The following VAT rates are provided:');

    $form['validate_vies'] = [
      '#type' => 'checkbox',
      '#title' => t('Validate tax numbers against VIES'),
      '#description' => t('Tax numbers will be checked against VIES.'),
      '#default_value' => $this->configuration['validate_vies'],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);

    $values = $form_state->getValue($form['#parents']);
    $this->configuration['validate_vies'] = $values['validate_vies'];
  }

  /**
   * {@inheritdoc}
   */
  protected function resolveZones(OrderItemInterface $order_item, ProfileInterface $customer_profile) {
    $zones = $this->getZones();
    $customer_address = $customer_profile->address->first();
    $customer_country = $customer_address->getCountryCode();
    $customer_zones = array_filter($zones, function ($zone) use ($customer_address) {
      /** @var \Drupal\commerce_tax\TaxZone $zone */
      return $zone->match($customer_address);
    });

    // If a store is in the EU, but the customer is from certain zones outside
    // of the EU, tax rates of eu countries still apply (e.g. Switzerland).
    // This is independent of tax registration.
    $external_zones = $this->getExternalZones();
    $customer_zones_external = array_filter($external_zones, function ($zone) use ($customer_address) {
      /** @var \Drupal\commerce_tax\TaxZone $zone */
      return $zone->match($customer_address);
    });

    if (empty($customer_zones) && empty($customer_zones_external)) {
      // The customer is not in the EU and can't be taxed by EU rules.
      return [];
    }

    $order = $order_item->getOrder();
    $store = $order->getStore();
    $store_address = $store->getAddress();
    $store_country = $store_address->getCountryCode();
    $store_zones = array_filter($zones, function ($zone) use ($store_address) {
      /** @var \Drupal\commerce_tax\TaxZone $zone */
      return $zone->match($store_address);
    });
    $store_registration_zones = array_filter($zones, function ($zone) use ($store) {
      /** @var \Drupal\commerce_tax\TaxZone $zone */
      return $this->checkRegistrations($store, $zone);
    });

    $customer_tax_number = $customer_profile->tax_number->value;;
    // Since january 1st 2015 all digital goods sold to EU customers
    // must use the customer zone. For example, an ebook sold
    // to Germany needs to have German VAT applied.
    $taxable_type = $this->getTaxableType($order_item);
    $year = $this->getCalculationDate($order)->format('Y');
    $is_digital = $taxable_type == TaxableType::DIGITAL_GOODS && $year >= 2015;

    // Since the point of origin for the taxation of events is neither the
    // store nor the customer tax zone, but the country the event is held,
    // we need to resolve an address of the event itself.
    $is_event = $taxable_type == TaxableType::EVENTS;

    $resolved_zones = [];
    if (empty($store_zones) && !empty($store_registration_zones)) {
      // The store is not in the EU but is registered to collect VAT.
      // This VAT is only charged on B2C digital services.
      $resolved_zones = [];
      if ($is_digital && !$customer_tax_number) {
        $resolved_zones = $customer_zones;
      }
    }
    elseif (!$is_event && $customer_tax_number && $customer_country != $store_country) {
      // Intra-community supply (B2B) for non-events.
      $resolved_zones = [$this->getIcZone()];
    }
    elseif ($is_event) {
      // Conferences, exhibitions or trainings will use the zone of the
      // address where the event is held.
      // http://www.vatlive.com/eu-vat-rules
      $product = $order_item->getPurchasedEntity()->getProduct();
      $product_type = $this->entityTypeManager->getStorage('commerce_product_type')->load($product->bundle());

      // If no address can be determined, tax based on store address.
      $resolved_zones = $store_zones;

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

          if ($customer_tax_number && $customer_country != $event_country) {
            $resolved_zones = [$this->getIcZone()];
          }
          else {
            $event_zones = array_filter($zones, function ($zone) use ($event_address) {
              /** @var \Drupal\commerce_tax\TaxZone $zone */
              return $zone->match($event_address);
            });

            $resolved_zones = $event_zones;
          }
        }
      }
    }
    elseif ($is_digital) {
      $resolved_zones = $customer_zones;
    }
    else {
      // Physical products use the origin zone, unless the store is
      // registered to pay taxes in the destination zone. This is required
      // when the total yearly transactions breach the defined threshold.
      // See http://www.vatlive.com/eu-vat-rules/vat-registration-threshold/
      $resolved_zones = $store_zones;
      $customer_zone = reset($customer_zones);
      if ($this->checkRegistrations($store, $customer_zone)) {
        $resolved_zones = $customer_zones;
      }
    }

    return $resolved_zones;
  }

  /**
   * {@inheritdoc}
   */
  protected function negativeRateIsApplicable($rates, $prices_include_tax, $matches_store_address) {
    $ic_rate = $this->getIcZone();
    if ((!$rates || (count($rates) == 1 && array_key_exists($ic_rate->getId(), $rates))) && $prices_include_tax && $matches_store_address) {
      return TRUE;
    }

    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function buildZones() {
    // Avoid instantiating the same labels dozens of times.
    $labels = [
      'standard' => $this->t('Standard'),
      'intermediate' => $this->t('Intermediate'),
      'reduced' => $this->t('Reduced'),
      'second_reduced' => $this->t('Second Reduced'),
      'super_reduced' => $this->t('Super Reduced'),
      'special' => $this->t('Special'),
      'zero' => $this->t('Zero'),
      'vat' => $this->t('VAT'),
    ];

    $zones = [];
    $zones['at'] = new TaxZone([
      'id' => 'at',
      'label' => $this->t('Austria'),
      'display_label' => $labels['vat'],
      'territories' => [
        // Austria without Jungholz and Mittelberg.
        ['country_code' => 'AT', 'excluded_postal_codes' => '6691, 6991:6993'],
      ],
      'rates' => [
        [
          'id' => 'standard',
          'label' => $labels['standard'],
          'percentages' => [
            ['number' => '0.2', 'start_date' => '1995-01-01'],
          ],
          'default' => TRUE,
        ],
        [
          'id' => 'intermediate',
          'label' => $labels['intermediate'],
          'percentages' => [
            ['number' => '0.13', 'start_date' => '2016-01-01'],
          ],
        ],
        [
          'id' => 'reduced',
          'label' => $labels['reduced'],
          'percentages' => [
            ['number' => '0.1', 'start_date' => '1995-01-01'],
          ],
        ],
      ],
    ]);
    $zones['be'] = new TaxZone([
      'id' => 'be',
      'label' => $this->t('Belgium'),
      'display_label' => $labels['vat'],
      'territories' => [
        ['country_code' => 'BE'],
      ],
      'rates' => [
        [
          'id' => 'standard',
          'label' => $labels['standard'],
          'percentages' => [
            ['number' => '0.21', 'start_date' => '1996-01-01'],
          ],
          'default' => TRUE,
        ],
        [
          'id' => 'intermediate',
          'label' => $labels['intermediate'],
          'percentages' => [
            ['number' => '0.12', 'start_date' => '1992-04-01'],
          ],
        ],
        [
          'id' => 'reduced',
          'label' => $labels['reduced'],
          'percentages' => [
            ['number' => '0.06', 'start_date' => '1971-01-01'],
          ],
        ],
        [
          'id' => 'zero',
          'label' => $labels['zero'],
          'percentages' => [
            ['number' => '0', 'start_date' => '1971-01-01'],
          ],
        ],
      ],
    ]);
    $zones['bg'] = new TaxZone([
      'id' => 'bg',
      'label' => $this->t('Bulgaria'),
      'display_label' => $labels['vat'],
      'territories' => [
        ['country_code' => 'BG'],
      ],
      'rates' => [
        [
          'id' => 'standard',
          'label' => $labels['standard'],
          'percentages' => [
            ['number' => '0.2', 'start_date' => '2007-01-01'],
          ],
          'default' => TRUE,
        ],
        [
          'id' => 'reduced',
          'label' => $labels['reduced'],
          'percentages' => [
            ['number' => '0.09', 'start_date' => '2011-04-01'],
          ],
        ],
      ],
    ]);
    $zones['cy'] = new TaxZone([
      'id' => 'cy',
      'label' => $this->t('Cyprus'),
      'display_label' => $labels['vat'],
      'territories' => [
        ['country_code' => 'CY'],
      ],
      'rates' => [
        [
          'id' => 'standard',
          'label' => $labels['standard'],
          'percentages' => [
            ['number' => '0.19', 'start_date' => '2014-01-13'],
          ],
          'default' => TRUE,
        ],
        [
          'id' => 'intermediate',
          'label' => $labels['intermediate'],
          'percentages' => [
            ['number' => '0.09', 'start_date' => '2014-01-13'],
          ],
        ],
        [
          'id' => 'reduced',
          'label' => $labels['reduced'],
          'percentages' => [
            ['number' => '0.05', 'start_date' => '2004-05-01'],
          ],
        ],
      ],
    ]);
    $zones['cz'] = new TaxZone([
      'id' => 'cz',
      'label' => $this->t('Czech Republic'),
      'display_label' => $labels['vat'],
      'territories' => [
        ['country_code' => 'CZ'],
      ],
      'rates' => [
        [
          'id' => 'standard',
          'label' => $labels['standard'],
          'percentages' => [
            ['number' => '0.21', 'start_date' => '2013-01-01'],
          ],
          'default' => TRUE,
        ],
        [
          'id' => 'reduced',
          'label' => $labels['reduced'],
          'percentages' => [
            ['number' => '0.15', 'start_date' => '2013-01-01'],
          ],
        ],
        [
          'id' => 'super_reduced',
          'label' => $labels['super_reduced'],
          'percentages' => [
            ['number' => '0.1', 'start_date' => '2015-01-01'],
          ],
        ],
        [
          'id' => 'zero',
          'label' => $labels['zero'],
          'percentages' => [
            ['number' => '0', 'start_date' => '2004-05-01'],
          ],
        ],
      ],
    ]);
    $zones['de'] = new TaxZone([
      'id' => 'de',
      'label' => $this->t('Germany'),
      'display_label' => $labels['vat'],
      'territories' => [
        // Germany without Heligoland and Büsingen.
        ['country_code' => 'DE', 'excluded_postal_codes' => '27498, 78266'],
        // Austria (Jungholz and Mittelberg).
        ['country_code' => 'AT', 'included_postal_codes' => '6691, 6991:6993'],
      ],
      'rates' => [
        [
          'id' => 'standard',
          'label' => $labels['standard'],
          'percentages' => [
            ['number' => '0.19', 'start_date' => '2007-01-01'],
          ],
          'default' => TRUE,
        ],
        [
          'id' => 'reduced',
          'label' => $labels['reduced'],
          'percentages' => [
            ['number' => '0.07', 'start_date' => '1983-07-01'],
          ],
        ],
      ],
    ]);
    $zones['dk'] = new TaxZone([
      'id' => 'dk',
      'label' => $this->t('Denmark'),
      'display_label' => $labels['vat'],
      'territories' => [
        ['country_code' => 'DK'],
      ],
      'rates' => [
        [
          'id' => 'standard',
          'label' => $labels['standard'],
          'percentages' => [
            ['number' => '0.25', 'start_date' => '1992-01-01'],
          ],
          'default' => TRUE,
        ],
        [
          'id' => 'zero',
          'label' => $labels['zero'],
          'percentages' => [
            ['number' => '0', 'start_date' => '1973-01-01'],
          ],
        ],
      ],
    ]);
    $zones['ee'] = new TaxZone([
      'id' => 'ee',
      'label' => $this->t('Estonia'),
      'display_label' => $labels['vat'],
      'territories' => [
        ['country_code' => 'EE'],
      ],
      'rates' => [
        [
          'id' => 'standard',
          'label' => $labels['standard'],
          'percentages' => [
            ['number' => '0.2', 'start_date' => '2009-07-01'],
          ],
          'default' => TRUE,
        ],
        [
          'id' => 'reduced',
          'label' => $labels['reduced'],
          'percentages' => [
            ['number' => '0.09', 'start_date' => '2009-01-01'],
          ],
        ],
      ],
    ]);
    $zones['es'] = new TaxZone([
      'id' => 'es',
      'label' => $this->t('Spain'),
      'display_label' => $labels['vat'],
      'territories' => [
        // Spain without Canary Islands, Ceuta and Melilla.
        ['country_code' => 'ES', 'excluded_postal_codes' => '/(35|38|51|52)[0-9]{3}/'],
      ],
      'rates' => [
        [
          'id' => 'standard',
          'label' => $labels['standard'],
          'percentages' => [
            ['number' => '0.21', 'start_date' => '2012-09-01'],
          ],
          'default' => TRUE,
        ],
        [
          'id' => 'reduced',
          'label' => $labels['reduced'],
          'percentages' => [
            ['number' => '0.1', 'start_date' => '2012-09-01'],
          ],
        ],
        [
          'id' => 'super_reduced',
          'label' => $labels['super_reduced'],
          'percentages' => [
            ['number' => '0.04', 'start_date' => '1995-01-01'],
          ],
        ],
      ],
    ]);
    $zones['fi'] = new TaxZone([
      'id' => 'fi',
      'label' => $this->t('Finland'),
      'display_label' => $labels['vat'],
      'territories' => [
        // Finland without Åland Islands.
        ['country_code' => 'FI', 'excluded_postal_codes' => '22000:22999'],
      ],
      'rates' => [
        [
          'id' => 'standard',
          'label' => $labels['standard'],
          'percentages' => [
            ['number' => '0.24', 'start_date' => '2013-01-01'],
          ],
          'default' => TRUE,
        ],
        [
          'id' => 'intermediate',
          'label' => $labels['intermediate'],
          'percentages' => [
            ['number' => '0.14', 'start_date' => '2013-01-01'],
          ],
        ],
        [
          'id' => 'reduced',
          'label' => $labels['reduced'],
          'percentages' => [
            ['number' => '0.1', 'start_date' => '2013-01-01'],
          ],
        ],
      ],
    ]);
    $zones['fr'] = new TaxZone([
      'id' => 'fr',
      'label' => $this->t('France'),
      'display_label' => $labels['vat'],
      'territories' => [
        // France without Corsica.
        ['country_code' => 'FR', 'excluded_postal_codes' => '/(20)[0-9]{3}/'],
        ['country_code' => 'MC'],
      ],
      'rates' => [
        [
          'id' => 'standard',
          'label' => $labels['standard'],
          'percentages' => [
            ['number' => '0.2', 'start_date' => '2014-01-01'],
          ],
          'default' => TRUE,
        ],
        [
          'id' => 'intermediate',
          'label' => $labels['intermediate'],
          'percentages' => [
            ['number' => '0.1', 'start_date' => '2014-01-01'],
          ],
        ],
        [
          'id' => 'reduced',
          'label' => $labels['reduced'],
          'percentages' => [
            ['number' => '0.055', 'start_date' => '1982-07-01'],
          ],
        ],
        [
          'id' => 'super_reduced',
          'label' => $labels['super_reduced'],
          'percentages' => [
            ['number' => '0.021', 'start_date' => '1986-07-01'],
          ],
        ],
      ],
    ]);
    $zones['fr_h'] = new TaxZone([
      'id' => 'fr_h',
      'label' => $this->t('France (Corsica)'),
      'display_label' => $labels['vat'],
      'territories' => [
        // France without Corsica.
        ['country_code' => 'FR', 'included_postal_codes' => '/(20)[0-9]{3}/'],
      ],
      'rates' => [
        [
          'id' => 'standard',
          'label' => $labels['standard'],
          'percentages' => [
            ['number' => '0.2', 'start_date' => '2014-01-01'],
          ],
          'default' => TRUE,
        ],
        [
          'id' => 'special',
          'label' => $labels['special'],
          'percentages' => [
            ['number' => '0.1', 'start_date' => '2014-01-01'],
          ],
        ],
        [
          'id' => 'reduced',
          'label' => $labels['reduced'],
          'percentages' => [
            ['number' => '0.021', 'start_date' => '1997-09-01'],
          ],
        ],
        [
          'id' => 'super_reduced',
          'label' => $labels['super_reduced'],
          'percentages' => [
            ['number' => '0.009', 'start_date' => '1972-04-01'],
          ],
        ],
      ],
    ]);
    $zones['gb'] = new TaxZone([
      'id' => 'gb',
      'label' => $this->t('United Kingdom'),
      'display_label' => $labels['vat'],
      'territories' => [
        ['country_code' => 'GB'],
        ['country_code' => 'IM'],
      ],
      'rates' => [
        [
          'id' => 'standard',
          'label' => $labels['standard'],
          'percentages' => [
            ['number' => '0.2', 'start_date' => '2011-01-04'],
          ],
          'default' => TRUE,
        ],
        [
          'id' => 'reduced',
          'label' => $labels['reduced'],
          'percentages' => [
            ['number' => '0.05', 'start_date' => '1997-09-01'],
          ],
        ],
        [
          'id' => 'zero',
          'label' => $labels['zero'],
          'percentages' => [
            ['number' => '0', 'start_date' => '1973-01-01'],
          ],
        ],
      ],
    ]);
    $zones['gr'] = new TaxZone([
      'id' => 'gr',
      'label' => $this->t('Greece'),
      'display_label' => $labels['vat'],
      'territories' => [
        // Greece without Thassos, Samothrace, Skiros, Northern Sporades, Lesbos, Chios, The Cyclades, The Dodecanese.
        ['country_code' => 'GR', 'excluded_postal_codes' => '/640 ?04|680 ?02|340 ?07|((370|811|821|840|851) ?[0-9]{2})/'],
      ],
      'rates' => [
        [
          'id' => 'standard',
          'label' => $labels['standard'],
          'percentages' => [
            ['number' => '0.23', 'start_date' => '2010-07-01', 'end_date' => '2015-05-31'],
            ['number' => '0.24', 'start_date' => '2016-06-01'],
          ],
          'default' => TRUE,
        ],
        [
          'id' => 'intermediate',
          'label' => $labels['intermediate'],
          'percentages' => [
            ['number' => '0.13', 'start_date' => '2011-01-01'],
          ],
        ],
        [
          'id' => 'reduced',
          'label' => $labels['reduced'],
          'percentages' => [
            ['number' => '0.06', 'start_date' => '2015-07-01'],
          ],
        ],
      ],
    ]);
    $zones['hr'] = new TaxZone([
      'id' => 'hr',
      'label' => $this->t('Croatia'),
      'display_label' => $labels['vat'],
      'territories' => [
        ['country_code' => 'HR'],
      ],
      'rates' => [
        [
          'id' => 'standard',
          'label' => $labels['standard'],
          'percentages' => [
            ['number' => '0.25', 'start_date' => '2013-07-01'],
          ],
          'default' => TRUE,
        ],
        [
          'id' => 'reduced',
          'label' => $labels['reduced'],
          'percentages' => [
            ['number' => '0.13', 'start_date' => '2014-01-01'],
          ],
        ],
        [
          'id' => 'super_reduced',
          'label' => $labels['super_reduced'],
          'percentages' => [
            ['number' => '0.05', 'start_date' => '2014-01-01'],
          ],
        ],
        [
          'id' => 'zero',
          'label' => $labels['zero'],
          'percentages' => [
            ['number' => '0', 'start_date' => '2013-07-01'],
          ],
        ],
      ],
    ]);
    $zones['hu'] = new TaxZone([
      'id' => 'hu',
      'label' => $this->t('Hungary'),
      'display_label' => $labels['vat'],
      'territories' => [
        ['country_code' => 'HU'],
      ],
      'rates' => [
        [
          'id' => 'standard',
          'label' => $labels['standard'],
          'percentages' => [
            ['number' => '0.27', 'start_date' => '2012-01-01'],
          ],
          'default' => TRUE,
        ],
        [
          'id' => 'intermediate',
          'label' => $labels['intermediate'],
          'percentages' => [
            ['number' => '0.18', 'start_date' => '2009-07-01'],
          ],
        ],
        [
          'id' => 'reduced',
          'label' => $labels['reduced'],
          'percentages' => [
            ['number' => '0.05', 'start_date' => '2004-05-01'],
          ],
        ],
      ],
    ]);
    $zones['ie'] = new TaxZone([
      'id' => 'ie',
      'label' => $this->t('Ireland'),
      'display_label' => $labels['vat'],
      'territories' => [
        ['country_code' => 'IE'],
      ],
      'rates' => [
        [
          'id' => 'standard',
          'label' => $labels['standard'],
          'percentages' => [
            ['number' => '0.23', 'start_date' => '2012-01-01'],
          ],
          'default' => TRUE,
        ],
        [
          'id' => 'reduced',
          'label' => $labels['reduced'],
          'percentages' => [
            ['number' => '0.135', 'start_date' => '2003-01-01'],
          ],
        ],
        [
          'id' => 'second_reduced',
          'label' => $labels['second_reduced'],
          'percentages' => [
            ['number' => '0.09', 'start_date' => '2011-07-01'],
          ],
        ],
        [
          'id' => 'super_reduced',
          'label' => $labels['super_reduced'],
          'percentages' => [
            ['number' => '0.048', 'start_date' => '2005-01-01'],
          ],
        ],
        [
          'id' => 'zero',
          'label' => $labels['zero'],
          'percentages' => [
            ['number' => '0', 'start_date' => '1972-04-01'],
          ],
        ],
      ],
    ]);
    $zones['it'] = new TaxZone([
      'id' => 'it',
      'label' => $this->t('Italy'),
      'display_label' => $labels['vat'],
      'territories' => [
        // Italy without Livigno, Campione d’Italia and Lake Lugano.
        ['country_code' => 'IT', 'excluded_postal_codes' => '23030, 22060'],
      ],
      'rates' => [
        [
          'id' => 'standard',
          'label' => $labels['standard'],
          'percentages' => [
            ['number' => '0.22', 'start_date' => '2013-10-01'],
          ],
          'default' => TRUE,
        ],
        [
          'id' => 'reduced',
          'label' => $labels['reduced'],
          'percentages' => [
            ['number' => '0.1', 'start_date' => '1995-02-24'],
          ],
        ],
        [
          'id' => 'super_reduced',
          'label' => $labels['super_reduced'],
          'percentages' => [
            ['number' => '0.04', 'start_date' => '1989-01-01'],
          ],
        ],
      ],
    ]);
    $zones['lt'] = new TaxZone([
      'id' => 'lt',
      'label' => $this->t('Lithuania'),
      'display_label' => $labels['vat'],
      'territories' => [
        ['country_code' => 'LT'],
      ],
      'rates' => [
        [
          'id' => 'standard',
          'label' => $labels['standard'],
          'percentages' => [
            ['number' => '0.21', 'start_date' => '2009-09-01'],
          ],
          'default' => TRUE,
        ],
        [
          'id' => 'intermediate',
          'label' => $labels['intermediate'],
          'percentages' => [
            ['number' => '0.09', 'start_date' => '2004-05-01'],
          ],
        ],
        [
          'id' => 'reduced',
          'label' => $labels['reduced'],
          'percentages' => [
            ['number' => '0.05', 'start_date' => '2004-05-01'],
          ],
        ],
      ],
    ]);
    $zones['lu'] = new TaxZone([
      'id' => 'lu',
      'label' => $this->t('Luxembourg'),
      'display_label' => $labels['vat'],
      'territories' => [
        ['country_code' => 'LU'],
      ],
      'rates' => [
        [
          'id' => 'standard',
          'label' => $labels['standard'],
          'percentages' => [
            ['number' => '0.17', 'start_date' => '2015-01-01'],
          ],
          'default' => TRUE,
        ],
        [
          'id' => 'intermediate',
          'label' => $labels['intermediate'],
          'percentages' => [
            ['number' => '0.14', 'start_date' => '2015-01-01'],
          ],
        ],
        [
          'id' => 'reduced',
          'label' => $labels['reduced'],
          'percentages' => [
            ['number' => '0.08', 'start_date' => '2015-01-01'],
          ],
        ],
        [
          'id' => 'super_reduced',
          'label' => $labels['super_reduced'],
          'percentages' => [
            ['number' => '0.03', 'start_date' => '1983-07-01'],
          ],
        ],
      ],
    ]);
    $zones['lv'] = new TaxZone([
      'id' => 'lv',
      'label' => $this->t('Latvia'),
      'display_label' => $labels['vat'],
      'territories' => [
        ['country_code' => 'LV'],
      ],
      'rates' => [
        [
          'id' => 'standard',
          'label' => $labels['standard'],
          'percentages' => [
            ['number' => '0.21', 'start_date' => '2012-07-01'],
          ],
          'default' => TRUE,
        ],
        [
          'id' => 'reduced',
          'label' => $labels['reduced'],
          'percentages' => [
            ['number' => '0.12', 'start_date' => '2011-01-01'],
          ],
        ],
      ],
    ]);
    $zones['mt'] = new TaxZone([
      'id' => 'mt',
      'label' => $this->t('Malta'),
      'display_label' => $labels['vat'],
      'territories' => [
        ['country_code' => 'MT'],
      ],
      'rates' => [
        [
          'id' => 'standard',
          'label' => $labels['standard'],
          'percentages' => [
            ['number' => '0.18', 'start_date' => '2004-05-01'],
          ],
          'default' => TRUE,
        ],
        [
          'id' => 'intermediate',
          'label' => $labels['intermediate'],
          'percentages' => [
            ['number' => '0.07', 'start_date' => '2011-01-01'],
          ],
        ],
        [
          'id' => 'reduced',
          'label' => $labels['reduced'],
          'percentages' => [
            ['number' => '0.05', 'start_date' => '2004-05-01'],
          ],
        ],
      ],
    ]);
    $zones['nl'] = new TaxZone([
      'id' => 'nl',
      'label' => $this->t('Netherlands'),
      'display_label' => $labels['vat'],
      'territories' => [
        ['country_code' => 'NL'],
      ],
      'rates' => [
        [
          'id' => 'standard',
          'label' => $labels['standard'],
          'percentages' => [
            ['number' => '0.21', 'start_date' => '2012-10-01'],
          ],
          'default' => TRUE,
        ],
        [
          'id' => 'reduced',
          'label' => $labels['reduced'],
          'percentages' => [
            ['number' => '0.06', 'start_date' => '1986-10-01'],
          ],
        ],
      ],
    ]);
    $zones['pl'] = new TaxZone([
      'id' => 'pl',
      'label' => $this->t('Poland'),
      'display_label' => $labels['vat'],
      'territories' => [
        ['country_code' => 'PL'],
      ],
      'rates' => [
        [
          'id' => 'standard',
          'label' => $labels['standard'],
          'percentages' => [
            ['number' => '0.23', 'start_date' => '2011-01-01'],
          ],
          'default' => TRUE,
        ],
        [
          'id' => 'intermediate',
          'label' => $labels['intermediate'],
          'percentages' => [
            ['number' => '0.08', 'start_date' => '2011-01-01'],
          ],
        ],
        [
          'id' => 'reduced',
          'label' => $labels['reduced'],
          'percentages' => [
            ['number' => '0.05', 'start_date' => '2011-01-01'],
          ],
        ],
      ],
    ]);
    $zones['pt'] = new TaxZone([
      'id' => 'pt',
      'label' => $this->t('Portugal'),
      'display_label' => $labels['vat'],
      'territories' => [
        // Portugal without Azores and Madeira.
        ['country_code' => 'PT', 'excluded_postal_codes' => '/(9)[0-9]{3}-[0-9]{3}/'],
      ],
      'rates' => [
        [
          'id' => 'standard',
          'label' => $labels['standard'],
          'percentages' => [
            ['number' => '0.23', 'start_date' => '2011-01-01'],
          ],
          'default' => TRUE,
        ],
        [
          'id' => 'intermediate',
          'label' => $labels['intermediate'],
          'percentages' => [
            ['number' => '0.13', 'start_date' => '2010-07-01'],
          ],
        ],
        [
          'id' => 'reduced',
          'label' => $labels['reduced'],
          'percentages' => [
            ['number' => '0.06', 'start_date' => '2010-07-01'],
          ],
        ],
      ],
    ]);
    $zones['pt_30'] = new TaxZone([
      'id' => 'pt_30',
      'label' => $this->t('Portugal (Madeira)'),
      'display_label' => $labels['vat'],
      'territories' => [
        ['country_code' => 'PT', 'included_postal_codes' => '/(9)[5-9][0-9]{2}-[0-9]{3}/'],
      ],
      'rates' => [
        [
          'id' => 'standard',
          'label' => $labels['standard'],
          'percentages' => [
            ['number' => '0.22', 'start_date' => '2012-04-01'],
          ],
          'default' => TRUE,
        ],
        [
          'id' => 'intermediate',
          'label' => $labels['intermediate'],
          'percentages' => [
            ['number' => '0.12', 'start_date' => '2012-04-01'],
          ],
        ],
        [
          'id' => 'reduced',
          'label' => $labels['reduced'],
          'percentages' => [
            ['number' => '0.05', 'start_date' => '2012-04-01'],
          ],
        ],
      ],
    ]);
    $zones['ro'] = new TaxZone([
      'id' => 'ro',
      'label' => $this->t('Romania'),
      'display_label' => $labels['vat'],
      'territories' => [
        ['country_code' => 'RO'],
      ],
      'rates' => [
        [
          'id' => 'standard',
          'label' => $labels['standard'],
          'percentages' => [
            ['number' => '0.20', 'start_date' => '2016-01-01', 'end_date' => '2016-12-31'],
            ['number' => '0.19', 'start_date' => '2017-01-01'],
          ],
          'default' => TRUE,
        ],
        [
          'id' => 'intermediate',
          'label' => $labels['intermediate'],
          'percentages' => [
            ['number' => '0.09', 'start_date' => '2008-12-01'],
          ],
        ],
        [
          'id' => 'reduced',
          'label' => $labels['reduced'],
          'percentages' => [
            ['number' => '0.05', 'start_date' => '2008-12-01'],
          ],
        ],
      ],
    ]);
    $zones['se'] = new TaxZone([
      'id' => 'se',
      'label' => $this->t('Sweden'),
      'display_label' => $labels['vat'],
      'territories' => [
        ['country_code' => 'SE'],
      ],
      'rates' => [
        [
          'id' => 'standard',
          'label' => $labels['standard'],
          'percentages' => [
            ['number' => '0.25', 'start_date' => '1995-01-01'],
          ],
          'default' => TRUE,
        ],
        [
          'id' => 'intermediate',
          'label' => $labels['intermediate'],
          'percentages' => [
            ['number' => '0.12', 'start_date' => '1995-01-01'],
          ],
        ],
        [
          'id' => 'reduced',
          'label' => $labels['reduced'],
          'percentages' => [
            ['number' => '0.06', 'start_date' => '1996-01-01'],
          ],
        ],
      ],
    ]);
    $zones['si'] = new TaxZone([
      'id' => 'si',
      'label' => $this->t('Slovenia'),
      'display_label' => $labels['vat'],
      'territories' => [
        ['country_code' => 'SI'],
      ],
      'rates' => [
        [
          'id' => 'standard',
          'label' => $labels['standard'],
          'percentages' => [
            ['number' => '0.22', 'start_date' => '2013-07-01'],
          ],
          'default' => TRUE,
        ],
        [
          'id' => 'reduced',
          'label' => $labels['reduced'],
          'percentages' => [
            ['number' => '0.095', 'start_date' => '2013-07-01'],
          ],
        ],
      ],
    ]);
    $zones['sk'] = new TaxZone([
      'id' => 'sk',
      'label' => $this->t('Slovakia'),
      'display_label' => $labels['vat'],
      'territories' => [
        ['country_code' => 'SK'],
      ],
      'rates' => [
        [
          'id' => 'standard',
          'label' => $labels['standard'],
          'percentages' => [
            ['number' => '0.2', 'start_date' => '2011-01-01'],
          ],
          'default' => TRUE,
        ],
        [
          'id' => 'reduced',
          'label' => $labels['reduced'],
          'percentages' => [
            ['number' => '0.1', 'start_date' => '2011-01-01'],
          ],
        ],
      ],
    ]);
    // Add special ic zone.
    $zones['ic'] = $this->getIcZone();

    return $zones;
  }

  /**
   * Gets the special Intra-Community Supply tax zone.
   *
   * Used by resolveZones() for cross-country B2B sales.
   *
   * @return \Drupal\commerce_tax\TaxZone
   *   The tax zone.
   */
  protected function getIcZone() {
    return new TaxZone([
      'id' => 'ic',
      'label' => $this->t('Intra-Community Supply'),
      'display_label' => $this->t('Intra-Community Supply'),
      'territories' => [
        // This territory won't match, but it doesn't need to.
        ['country_code' => 'EU'],
      ],
      'rates' => [
        [
          'id' => 'ic',
          'label' => $this->t('Intra-Community Supply'),
          'percentages' => [
            ['number' => '0', 'start_date' => '1970-01-01'],
          ],
          'default' => TRUE,
        ],
      ],
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getExternalZones() {
    // @todo: We should get this from the SwissVat Tax type to avoid duplication.
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
      unset($possible_country_codes['GR']);
      $possible_country_codes['EL'] = 'EL';

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
      // If no soap extension is loaded we can't check the validity.
      if (!extension_loaded('soap')) {
        return FALSE;
      }

      // Only the part without the country code gets checked.
      $tax_number_country = mb_substr($tax_number->getTaxNumber(), 2);

      try {
        $client = new \SoapClient("http://ec.europa.eu/taxation_customs/vies/checkVatService.wsdl");
        $response = $client->checkVat([
          'countryCode' => $country_code,
          'vatNumber' => $tax_number_country,
        ]);

        if ($response->valid) {
          return TRUE;
        }
      }
      catch (\SoapFault $e) {
        watchdog_exception('vat_number', $e);
      }
    }

    return FALSE;
  }

}
