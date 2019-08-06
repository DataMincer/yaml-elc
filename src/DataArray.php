<?php

namespace YamlElc;

use Iterator;

class DataArray implements Iterator {

  protected $values = [];
  protected $conditions;

  protected $reference;

  private $position = 0;

  public function __construct() {
    $this->position = 0;
  }

  public function current() {
    return [$this->values[$this->position], $this->conditions[$this->position]];
  }

  public function next() {
    ++$this->position;
  }

  public function key() {
    return $this->position;
  }

  public function valid() {
    return array_key_exists($this->position, $this->values);
  }

  public function rewind() {
    $this->position = 0;
  }

  public function addItem($value, $conditions) {
    $this->values[] = $value;
    $this->conditions[] = $conditions;
  }

}
