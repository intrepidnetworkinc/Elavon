<?php

namespace Drupal\commerce_elavon;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Maps Converge AVS response codes to labels.
 */
class AvsResponses implements ContainerInjectionInterface {

  use StringTranslationTrait;

  /**
   * Translated labels, keyed by AVS code. Built on first use.
   *
   * @var array
   */
  protected $labels = [];

  /**
   * Constructs the helper.
   */
  public function __construct(TranslationInterface $string_translation) {
    $this->setStringTranslation($string_translation);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('string_translation')
    );
  }

  /**
   * TRUE when this code has a known label.
   */
  public function hasLabel($avs_code): bool {
    $this->ensureLabels();
    return isset($this->labels[$avs_code]);
  }

  /**
   * Returns the label for a known AVS code.
   *
   * @throws \InvalidArgumentException
   *   When the code is not in the map.
   */
  public function getLabel($avs_code) {
    $this->ensureLabels();
    if (!isset($this->labels[$avs_code])) {
      throw new \InvalidArgumentException(sprintf("Unexpected AVS code '%s'", $avs_code));
    }
    return $this->labels[$avs_code];
  }

  /**
   * Builds the label map once.
   */
  protected function ensureLabels(): void {
    if ($this->labels) {
      return;
    }
    $this->labels = [
      'A' => $this->t('Address matches - ZIP Code does not match'),
      'B' => $this->t('Street address match, Postal code in wrong format (international issuer)'),
      'C' => $this->t('Street address and postal code in wrong formats'),
      'D' => $this->t('Street address and postal code match (international issuer)'),
      'E' => $this->t('AVS Error'),
      'F' => $this->t('Address does compare and five-digit ZIP code does compare (UK only)'),
      'G' => $this->t('Service not supported by non-US issuer'),
      'I' => $this->t('Address information not verified by international issuer'),
      'M' => $this->t('Street Address and Postal code match (international issuer)'),
      'N' => $this->t('No Match on Address (Street) or ZIP'),
      'O' => $this->t('No Response sent'),
      'P' => $this->t('Postal codes match, Street address not verified due to incompatible formats'),
      'R' => $this->t('Retry, System unavailable or Timed out'),
      'S' => $this->t('Service not supported by issuer'),
      'U' => $this->t('Address information is unavailable'),
      'W' => $this->t('9-digit ZIP matches, Address (Street) does not match'),
      'X' => $this->t('Exact AVS Match'),
      'Y' => $this->t('Address (Street) and 5-digit ZIP match'),
      'Z' => $this->t('5-digit ZIP matches, Address (Street) does not match'),
    ];
  }

}
