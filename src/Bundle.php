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

  public function __construct($name, array $data, $filters, $extra_data = []) {
    $this->name = $name;
    $this->extraData = $extra_data;
    $this->config = YamlElc::parse($data, $this->config);
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

}
