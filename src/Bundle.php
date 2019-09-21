<?php

namespace YamlElc;

class Bundle {

  protected $name;
  /** @var Config */
  protected $config;
  protected $products;
  protected $productsInfo;
  /** @var array */
  protected $extraData;

  public function __construct($name, array $data, $filters, $overrides = [], $extra_data = []) {
    $this->name = $name;
    $this->extraData = $extra_data;
    if (!empty($overrides)) {
      $data = $this->applyOverrides($data, $overrides);
    }
    $this->config = YamlElc::parse($data);
    $this->config->filter($filters);
    foreach ($this->config->generate() as $product_info) {
      $this->productsInfo[] = $product_info;
      $this->products[] = $this->config->render($product_info);
    }
  }

  public function name() {
    return $this->name;
  }

  /**
   * @return Config
   */
  public function getConfig() {
    return $this->config;
  }

  /**
   * @return array
   */
  public function getProducts() {
    return $this->products;
  }

  /**
   * @return array
   */
  public function getProductsInfo() {
    return $this->productsInfo;
  }

  /**
   * @return array
   */
  public function getExtraData() {
    return $this->extraData;
  }

  protected function applyOverrides($data, $patch) {
    return static::arrayMergeDeep($data, $patch);
  }

  protected static function arrayMergeDeep($array1, $array2, $reverse = FALSE, $preserve_integer_keys = FALSE) {
    return static::arrayMergeDeepArray([$array1, $array2], $reverse, $preserve_integer_keys);
  }

  protected static function arrayMergeDeepArray(array $arrays, $reverse = FALSE, $preserve_integer_keys = FALSE) {
    $result = [];
    foreach ($arrays as $array) {
      foreach ($array as $key => $value) {

        // Renumber integer keys as array_merge_recursive() does unless
        // $preserve_integer_keys is set to TRUE. Note that PHP automatically
        // converts array keys that are integer strings (e.g., '1') to integers.
        if (is_integer($key) && !$preserve_integer_keys) {
          $result[] = $value;
        }
        elseif (isset($result[$key]) && is_array($result[$key]) && is_array($value)) {
          $result[$key] = static::arrayMergeDeepArray([$result[$key], $value], $reverse, $preserve_integer_keys);
        }
        else {
          if (!$reverse || !array_key_exists($key, $result)) {
            $result[$key] = $value;
          }
        }
      }
    }
    return $result;
  }

}
